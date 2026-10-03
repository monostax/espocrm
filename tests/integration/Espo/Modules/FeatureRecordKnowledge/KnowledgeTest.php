<?php

declare(strict_types=1);

namespace tests\integration\Espo\Modules\FeatureRecordKnowledge;

use Espo\Core\Application;
use Espo\Core\Application\ApplicationParams;
use Espo\Core\Exceptions\Conflict;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Api\RequestWrapper;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use Espo\Core\Record\ServiceContainer;
use Espo\Core\Utils\File\Manager;
use Espo\Core\Utils\Config\ConfigWriter;
use Espo\Core\Select\SelectBuilderFactory;
use Espo\Core\Acl;
use Espo\Modules\FeatureRecordKnowledge\Services\Knowledge;
use Espo\Modules\FeatureRecordKnowledge\Services\Overviews;
use Espo\Modules\FeatureRecordKnowledge\Services\Relations;
use Espo\Modules\FeatureRecordKnowledge\Classes\Record\Restore;
use Espo\Modules\FeatureRecordKnowledge\Controllers\RecordKnowledge;
use Espo\Modules\FeatureRecordKnowledge\Scripts\Backfill;
use Espo\Modules\FeatureKnowledgeBaseEditor\Services\ReferenceIndex;
use Espo\ORM\EntityManager;
use Espo\ORM\Query\SelectBuilder;
use PHPUnit\Framework\TestCase;

/** Isolated runtime; TEST_DATABASE_NAME must point to a disposable empty database. */
class KnowledgeTest extends TestCase
{
    private static string $source;
    private static string $runtime;
    private Application $app;
    private EntityManager $em;

    public static function setUpBeforeClass(): void
    {
        if (!getenv('TEST_DATABASE_NAME')) self::markTestSkipped('Set TEST_DATABASE_* for an isolated database.');
        self::$source = getcwd();
        self::$runtime = getenv('RECORD_KNOWLEDGE_TEST_RUNTIME') ?: '/tmp/opencode/record-knowledge-' . bin2hex(random_bytes(4));
        $files = new Manager();
        $files->mkdir(self::$runtime);
        foreach (['application', 'vendor', 'client', 'install', 'html', 'public', 'index.php'] as $path) {
            if (!file_exists(self::$runtime . '/' . $path)) symlink(self::$source . '/' . $path, self::$runtime . '/' . $path);
        }
        foreach (['bootstrap.php', 'command.php', 'run-module-script.php'] as $path) {
            if (is_link(self::$runtime . '/' . $path)) unlink(self::$runtime . '/' . $path);
            copy(self::$source . '/' . $path, self::$runtime . '/' . $path);
        }
        $files->copy(self::$source . '/custom', self::$runtime . '/custom', true);
        $custom = self::$runtime . '/custom/Espo/Custom/Resources/metadata';
        $files->putContents($custom . '/scopes/CustomProject.json', json_encode([
            'entity' => true, 'object' => true, 'tab' => true, 'layouts' => true, 'acl' => true, 'module' => 'Custom',
        ]));
        $files->putContents($custom . '/entityDefs/CustomProject.json', json_encode([
            'fields' => ['name' => ['type' => 'varchar'], 'description' => ['type' => 'text']],
        ]));
        $files->mkdir(self::$runtime . '/data');
        chdir(self::$runtime);
        set_include_path(self::$runtime);
        require_once self::$source . '/install/core/Installer.php';
        $params = new ApplicationParams(noErrorHandler: true);
        $installer = new \Installer($params);
        $config = ['database' => [
            'platform' => getenv('TEST_DATABASE_PLATFORM') ?: 'Postgresql', 'host' => getenv('TEST_DATABASE_HOST') ?: '127.0.0.1',
            'port' => getenv('TEST_DATABASE_PORT') ?: '55438', 'dbname' => getenv('TEST_DATABASE_NAME'),
            'user' => getenv('TEST_DATABASE_USER') ?: 'postgres', 'password' => getenv('TEST_DATABASE_PASSWORD'),
        ], 'siteUrl' => 'http://127.0.0.1:8098', 'useCache' => false, 'isInstalled' => true, 'version' => '10.0.2'];
        $installer->saveData($config);
        $installer->saveConfig($config);
        $installer->rebuild();
        $installer->setSuccess();
    }

    protected function setUp(): void
    {
        chdir(self::$runtime);
        $this->app = new Application(new ApplicationParams(noErrorHandler: true));
        $this->app->setupSystemUser();
        $this->em = $this->app->getContainer()->get('entityManager');
    }

    private function service(string $class): object { return $this->app->getContainer()->get('injectableFactory')->create($class); }

    public function testCreationAndRepeatedBackfillAreIdempotentAndTerminal(): void
    {
        $account = $this->em->createEntity('Account', ['name' => 'Acme', 'description' => "# Acme\n\nSource  \n"]);
        $overviews = $this->service(Overviews::class);
        $binding = $overviews->ensure($account);
        $this->assertSame($binding->getId(), $overviews->ensure($account)->getId());
        $document = $this->em->getEntityById('Document', $binding->get('overviewDocumentId'));
        $this->assertSame("# Acme\n\nSource  \n", $document->get('body'));
        $this->assertNull($overviews->ensure($document));
        $this->assertSame(1, $this->em->getRDBRepository('RecordDocument')->where(['recordType' => 'Account', 'recordId' => $account->getId()])->count());
        $this->assertSame(1, $this->em->getRDBRepository('DocumentRevision')->where(['documentId' => $document->getId()])->count());
        (new Backfill())->run($this->app->getContainer());
        (new Backfill())->run($this->app->getContainer());
        $this->assertSame($binding->getId(), $overviews->binding('Account', $account->getId())->getId());
    }

    public function testRollbackLeavesNeitherParentNorBindingNorPage(): void
    {
        $before = $this->em->getRDBRepository('Document')->count();
        $id = null;
        try {
            $this->em->getTransactionManager()->run(function () use (&$id) {
                $account = $this->em->createEntity('Account', ['name' => 'Rollback']);
                $id = $account->getId();
                throw new \RuntimeException('Injected failure after provisioning.');
            });
        } catch (\RuntimeException) {}
        $this->assertNull($this->em->getEntityById('Account', $id));
        $this->assertNull($this->service(Overviews::class)->binding('Account', $id));
        $this->assertSame($before, $this->em->getRDBRepository('Document')->count());
    }

    public function testIndependentWritesImmutableEvidenceDecisionStalenessAndNativeRelations(): void
    {
        $account = $this->em->createEntity('Account', ['name' => 'Evidence Acme']);
        $contact = $this->em->createEntity('Contact', ['firstName' => 'João', 'lastName' => 'Example']);
        $knowledge = $this->service(Knowledge::class);
        $first = $knowledge->read('Contact', $contact->getId());
        $source = "---\ntitle: João\n---\n\nJoão works at Acme.\n\n[Acme](#crm-reference/v1/record/Account/{$account->getId()})\n";
        $current = $knowledge->write('Contact', $contact->getId(), $source, $first['versionNumber']);
        $relations = $this->service(Relations::class);
        $input = (object) ['subjectType' => 'Contact', 'subjectId' => $contact->getId(), 'predicate' => 'works_at',
            'objectType' => 'Account', 'objectId' => $account->getId(), 'sourceRevisionId' => $current['revision']['id'],
            'evidenceQuote' => 'João works at Acme.', 'idempotencyKey' => 'proposal-' . $contact->getId(), 'qualifiers' => (object) ['role' => 'CTO']];
        $claim = $relations->submit($input);
        $this->assertSame('suggested', $claim['status']);
        $this->assertSame($claim['id'], $relations->submit($input)['id']);
        $confirmed = $relations->decide($claim['id'], 'confirmed');
        $this->assertNotEmpty($confirmed['decidedById']);
        $this->assertNotEmpty($confirmed['decidedAt']);
        $this->assertSame('confirmed', $relations->decide($claim['id'], 'confirmed')['status']);
        $edited = $knowledge->write('Contact', $contact->getId(), "New heading\n\n" . $source, $current['versionNumber']);
        $anchored = $relations->list('Contact', $contact->getId())['list'][0];
        $this->assertSame($claim['sourceRevisionId'], $anchored['sourceRevisionId']);
        $this->assertSame($edited['revision']['id'], $anchored['anchorRevisionId']);
        $this->assertSame('confirmed', $anchored['status']);
        $knowledge->write('Contact', $contact->getId(), 'Unsupported now.', $edited['versionNumber']);
        $this->assertSame('stale', $relations->list('Contact', $contact->getId())['list'][0]['status']);
        $this->assertSame($source, $knowledge->revision($current['revision']['id'])['body']);
        $this->assertNull($this->em->getEntityById('Contact', $contact->getId())->get('description'));
        $opportunity = $this->em->createEntity('Opportunity', ['name' => 'Native deal', 'accountId' => $account->getId()]);
        $native = array_values(array_filter($relations->list('Account', $account->getId(), 'incoming')['list'], fn ($r) => $r['origin'] === 'native'));
        $this->assertSame($opportunity->getId(), $native[0]['subjectId']);
        $this->assertSame('account', $native[0]['provenance']['field']);
    }

    public function testVersionConflictsAndCanonicalDeleteRestore(): void
    {
        $account = $this->em->createEntity('Account', ['name' => 'Restore Acme']);
        $knowledge = $this->service(Knowledge::class);
        $first = $knowledge->read('Account', $account->getId());
        $knowledge->write('Account', $account->getId(), 'changed', $first['versionNumber']);
        try { $knowledge->write('Account', $account->getId(), 'lost update', $first['versionNumber']); $this->fail('Conflict expected.'); }
        catch (Conflict) { $this->addToAssertionCount(1); }
        $document = $this->em->getEntityById('Document', $first['documentId']);
        try { $this->em->removeEntity($document); $this->fail('Canonical deletion must fail.'); }
        catch (Forbidden) { $this->addToAssertionCount(1); }
        $this->em->removeEntity($account);
        $this->assertNull($this->em->getEntityById('Document', $first['documentId']));
        $deleted = $this->em->getRDBRepository('Account')->clone(SelectBuilder::create()->from('Account')->withDeleted()->where(['id' => $account->getId()])->build())->findOne();
        $this->service(Restore::class)->restore($deleted);
        $this->assertSame('changed', $knowledge->read('Account', $account->getId())['body']);
        $this->assertSame($first['documentId'], $knowledge->read('Account', $account->getId())['documentId']);
    }

    public function testMarkdownBacklinksRevalidateCanonicalContent(): void
    {
        $account = $this->em->createEntity('Account', ['name' => 'Backlinks Acme']);
        $contact = $this->em->createEntity('Contact', ['firstName' => 'Backlink', 'lastName' => 'Example']);
        $knowledge = $this->service(Knowledge::class);
        $first = $knowledge->read('Contact', $contact->getId());
        $source = "[Acme](#crm-reference/v1/record/Account/{$account->getId()})";
        $current = $knowledge->write('Contact', $contact->getId(), $source, $first['versionNumber']);
        $index = $this->service(ReferenceIndex::class);
        $this->assertSame($current['documentId'], $index->list('Account', $account->getId(), '')['list'][0]['recordId']);
        $knowledge->write('Contact', $contact->getId(), "`$source`", $current['versionNumber']);
        $this->assertSame([], $index->list('Account', $account->getId(), '')['list']);
        $this->em->createEntity('EditorReferenceIndex', ['sourceType' => 'Document', 'sourceId' => $current['documentId'], 'sourceField' => 'body',
            'targetType' => 'Account', 'targetId' => $account->getId()]);
        $this->assertSame([], $index->list('Account', $account->getId(), '')['list']);
    }

    public function testCustomApiCreationAndConcurrentBackfillHaveExactlyOneBinding(): void
    {
        $project = $this->service(ServiceContainer::class)->get('CustomProject')->create((object) ['name' => 'API custom project'])->getEntity();
        $this->assertNotNull($this->service(Overviews::class)->binding('CustomProject', $project->getId()));
        $account = $this->em->getNewEntity('Account');
        $account->set('name', 'Concurrent backfill');
        $this->em->saveEntity($account, ['skipHooks' => true]);
        $this->workers('ensure', ['Account', $account->getId()]);
        $this->assertSame(1, $this->em->getRDBRepository('RecordDocument')->where(['recordType' => 'Account', 'recordId' => $account->getId()])->count());
        $this->assertSame(1, $this->em->getRDBRepository('Document')->where(['knowledgeRecordType' => 'Account', 'knowledgeRecordId' => $account->getId()])->count());
    }

    public function testConcurrentProposalRetriesAndMismatchedPayloadAreDeterministic(): void
    {
        $account = $this->em->createEntity('Account', ['name' => 'Retry Acme']);
        $contact = $this->em->createEntity('Contact', ['firstName' => 'Retry', 'lastName' => 'Person', 'description' => 'Retry person works at Acme.']);
        $current = $this->service(Knowledge::class)->read('Contact', $contact->getId());
        $input = (object) ['subjectType' => 'Contact', 'subjectId' => $contact->getId(), 'predicate' => 'works_at',
            'objectType' => 'Account', 'objectId' => $account->getId(), 'sourceRevisionId' => $current['revision']['id'],
            'evidenceQuote' => 'Retry person works at Acme.', 'idempotencyKey' => 'concurrent-' . $contact->getId()];
        $this->workers('propose', [base64_encode(json_encode($input))]);
        $this->assertSame(1, $this->em->getRDBRepository('RecordRelation')->where(['subjectType' => 'Contact', 'subjectId' => $contact->getId()])->count());
        $input->qualifiers = (object) ['role' => 'Different payload'];
        $this->expectException(Conflict::class);
        $this->service(Relations::class)->submit($input);
    }

    public function testParentAccessConstrainsDirectDocumentListsExportAndRevisions(): void
    {
        $account = $this->em->createEntity('Account', ['name' => 'Private parent']);
        $document = $this->service(Knowledge::class)->read('Account', $account->getId());
        $role = $this->em->createEntity('Role', ['name' => 'Knowledge limited', 'data' => (object) [
            'Account' => (object) ['read' => 'no', 'create' => 'no', 'edit' => 'no', 'delete' => 'no'],
            'Document' => (object) ['read' => 'all', 'create' => 'no', 'edit' => 'no', 'delete' => 'no'],
        ]]);
        $user = $this->em->createEntity('User', ['emailAddress' => 'knowledge-limited-' . $account->getId() . '@example.test',
            'firstName' => 'Limited', 'lastName' => 'User', 'type' => 'regular', 'isActive' => true]);
        $this->em->getRelation($user, 'roles')->relate($role);
        $user = $this->em->getEntityById('User', $user->getId());
        $limited = new Application(new ApplicationParams(noErrorHandler: true));
        $limited->getContainer()->set('user', $user);
        $factory = $limited->getContainer()->get('injectableFactory');
        $this->assertFalse($limited->getContainer()->get('acl')->checkEntityRead($this->em->getEntityById('Document', $document['documentId'])));
        $query = $factory->create(SelectBuilderFactory::class)->create()->from('Document')->withStrictAccessControl()->buildQueryBuilder()
            ->where(['id' => $document['documentId']])->build();
        $this->assertNull($this->em->getRDBRepository('Document')->clone($query)->findOne());
        $knowledge = $factory->create(Knowledge::class);
        foreach ([fn () => $knowledge->read('Account', $account->getId()), fn () => $knowledge->export('Account', $account->getId()),
            fn () => $knowledge->revision($document['revision']['id'])] as $read) {
            try { $read(); $this->fail('Hidden parent knowledge was published.'); }
            catch (Forbidden|NotFound) { $this->addToAssertionCount(1); }
        }
    }

    private function workers(string $job, array $arguments): void
    {
        $processes = [];
        for ($i = 0; $i < 3; $i++) {
            $process = proc_open([PHP_BINARY, self::$source . '/tests/integration/fixtures/record-knowledge-worker.php',
                self::$source, self::$runtime, $job, ...$arguments], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            $processes[] = [$process, $pipes];
        }
        foreach ($processes as [$process, $pipes]) {
            $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]);
            $this->assertSame(0, proc_close($process), $output);
        }
    }

    public function testAuthenticatedControllerWritesRequireVersionAndExportStableIdentity(): void
    {
        $account = $this->em->createEntity('Account', ['name' => 'Controller Acme']);
        $controller = $this->service(RecordKnowledge::class);
        $query = ['recordType' => 'Account', 'recordId' => $account->getId()];
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/RecordKnowledge/overview')->withQueryParams($query);
        $overview = $controller->getActionOverview(new RequestWrapper($request));
        $write = $request->withMethod('PUT')->withHeader('Content-Type', 'application/json')->withBody(
            (new StreamFactory())->createStream(json_encode(['body' => "\n# Controller source  \n"])));
        try { $controller->putActionOverview(new RequestWrapper($write)); $this->fail('Missing version accepted.'); }
        catch (BadRequest) { $this->addToAssertionCount(1); }
        $saved = $controller->putActionOverview(new RequestWrapper($write->withHeader('X-Version-Number', (string) $overview->versionNumber)));
        $this->assertSame("\n# Controller source  \n", $saved->body);
        $artifact = $controller->getActionExport(new RequestWrapper($request));
        $this->assertStringContainsString('crm-record-knowledge/v1', $artifact->content);
        $this->assertStringEndsWith($saved->body, $artifact->content);
        $this->assertSame($saved->revision['id'], $controller->getActionRevision(new RequestWrapper(
            $request->withQueryParams(['id' => $saved->revision['id']])))->id);
    }

    public function testAssistantCanProposeButCannotConfirmOrAuthor(): void
    {
        $account = $this->em->createEntity('Account', ['name' => 'Assistant Acme']);
        $contact = $this->em->createEntity('Contact', ['firstName' => 'Assistant', 'lastName' => 'Subject', 'description' => 'Subject works at Acme.']);
        $overview = $this->service(Knowledge::class)->read('Contact', $contact->getId());
        $role = $this->em->createEntity('Role', ['name' => 'Knowledge API read', 'data' => (object) array_fill_keys(['Account', 'Contact', 'Document'],
            (object) ['read' => 'all', 'edit' => 'no', 'create' => 'no', 'delete' => 'no'])]);
        $user = $this->em->createEntity('User', ['userName' => 'knowledge-api-' . $contact->getId(), 'type' => 'api', 'isActive' => true]);
        $this->em->getRelation($user, 'roles')->relate($role);
        $actor = new Application(new ApplicationParams(noErrorHandler: true));
        $actor->getContainer()->set('user', $this->em->getEntityById('User', $user->getId()));
        $relations = $actor->getContainer()->get('injectableFactory')->create(Relations::class);
        $input = (object) ['subjectType' => 'Contact', 'subjectId' => $contact->getId(), 'predicate' => 'works_at',
            'objectType' => 'Account', 'objectId' => $account->getId(), 'sourceRevisionId' => $overview['revision']['id'],
            'evidenceQuote' => 'Subject works at Acme.', 'idempotencyKey' => 'assistant-proposal'];
        $claim = $relations->submit($input);
        $this->assertSame('suggested', $claim['status']);
        $this->assertSame('assistant', $claim['origin']);
        $this->assertSame($user->getId(), $claim['createdById']);
        foreach ([fn () => $relations->decide($claim['id'], 'confirmed'), fn () => $relations->submit($input, true)] as $write) {
            try { $write(); $this->fail('Assistant decided its own proposal.'); }
            catch (Forbidden) { $this->addToAssertionCount(1); }
        }
        $this->assertSame('rejected', $this->service(Relations::class)->decide($claim['id'], 'rejected')['status']);
    }

    public static function tearDownAfterClass(): void { if (isset(self::$source)) chdir(self::$source); }
}

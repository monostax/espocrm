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
use Espo\Modules\FeatureRecordKnowledge\Services\PredicateRegistry;
use Espo\Modules\FeatureRecordKnowledge\Services\Tenancy;
use Espo\Modules\FeatureRecordKnowledge\Tools\QualifierSchema;
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
    private static string $tenantId;
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
            'fields' => ['name' => ['type' => 'varchar'], 'description' => ['type' => 'text'], 'tenant' => ['type' => 'link']],
            'links' => ['tenant' => ['type' => 'belongsTo', 'entity' => 'Tenant']],
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
        $app = new Application(new ApplicationParams(noErrorHandler: true));
        $app->setupSystemUser();
        $em = $app->getContainer()->get('entityManager');
        $tenant = $em->getRDBRepository('Tenant')->where(['slug' => 'record-knowledge-tests'])->findOne()
            ?? $em->createEntity('Tenant', ['name' => 'Record Knowledge Tests', 'slug' => 'record-knowledge-tests']);
        self::$tenantId = $tenant->getId();
    }

    protected function setUp(): void
    {
        chdir(self::$runtime);
        $this->app = new Application(new ApplicationParams(noErrorHandler: true));
        $this->app->setupSystemUser();
        $this->em = $this->app->getContainer()->get('entityManager');
    }

    private function service(string $class): object { return $this->app->getContainer()->get('injectableFactory')->create($class); }

    private function owned(string $type, array $values, ?string $tenantId = null): \Espo\ORM\Entity
    {
        return $this->em->createEntity($type, [...$values, 'tenantId' => $tenantId ?? self::$tenantId]);
    }

    public function testCreationAndRepeatedBackfillAreIdempotentAndTerminal(): void
    {
        $body = "# Acme\n\n<https://example.test>\n\n```html\n<div>example</div>\n```\n\nSource  \n";
        $account = $this->em->createEntity('Account', ['name' => 'Acme', 'description' => $body]);
        $overviews = $this->service(Overviews::class);
        $binding = $overviews->ensure($account);
        $this->assertSame($binding->getId(), $overviews->ensure($account)->getId());
        $document = $this->em->getEntityById('Document', $binding->get('overviewDocumentId'));
        $this->assertSame($body, $document->get('body'));
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
        $account = $this->owned('Account', ['name' => 'Evidence Acme']);
        $contact = $this->owned('Contact', ['firstName' => 'João', 'lastName' => 'Example']);
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
        $opportunity = $this->owned('Opportunity', ['name' => 'Native deal', 'accountId' => $account->getId()]);
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
        $account = $this->owned('Account', ['name' => 'Retry Acme']);
        $contact = $this->owned('Contact', ['firstName' => 'Retry', 'lastName' => 'Person', 'description' => 'Retry person works at Acme.']);
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
                self::$source, self::$runtime, $job, ...$arguments, (string) $i], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
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
        $account = $this->owned('Account', ['name' => 'Assistant Acme']);
        $contact = $this->owned('Contact', ['firstName' => 'Assistant', 'lastName' => 'Subject', 'description' => 'Subject works at Acme.']);
        $overview = $this->service(Knowledge::class)->read('Contact', $contact->getId());
        $role = $this->em->createEntity('Role', ['name' => 'Knowledge API read', 'data' => (object) array_fill_keys(['Account', 'Contact', 'Document', 'RecordPredicate'],
            (object) ['read' => 'all', 'edit' => 'no', 'create' => 'no', 'delete' => 'no'])]);
        $user = $this->em->createEntity('User', ['userName' => 'knowledge-api-' . $contact->getId(), 'type' => 'api', 'isActive' => true]);
        $this->em->getRelation($user, 'roles')->relate($role);
        $this->em->getRelation($this->em->getEntityById('Tenant', self::$tenantId), 'users')->relate($user);
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

    private function predicate(string $tenantId, ?string $code = null, array $extra = []): \Espo\ORM\Entity
    {
        $code ??= 'advises_' . bin2hex(random_bytes(4));
        return $this->em->createEntity('RecordPredicate', [
            'tenantId' => $tenantId, 'code' => $code, 'name' => 'Advises ' . $code, 'inverseLabel' => 'Advised by',
            'subjectTypes' => ['CustomProject'], 'objectTypes' => ['Account'], 'qualifierSchema' => QualifierSchema::shorthand([]),
            'aliases' => [], 'isActive' => true, ...$extra,
        ]);
    }

    private function tenant(): \Espo\ORM\Entity
    {
        $code = bin2hex(random_bytes(4));
        return $this->em->createEntity('Tenant', ['name' => 'Predicate tenant ' . $code, 'slug' => 'predicate-' . $code]);
    }

    private function actor(array $tenants, array $adminTenants = []): Application
    {
        $user = $this->em->createEntity('User', ['emailAddress' => 'predicate-' . bin2hex(random_bytes(6)) . '@example.test',
            'firstName' => 'Predicate', 'lastName' => 'User', 'type' => 'regular', 'isActive' => true]);
        foreach ($tenants as $id) $this->em->getRelation($this->em->getEntityById('Tenant', $id), 'users')->relate($user);
        $provisioner = $this->service(\Espo\Modules\Global\Tools\Tenant\TenantAdminTeamProvisioner::class);
        foreach ($adminTenants as $id) {
            $teamId = $provisioner->findAdminTeamId($this->em->getEntityById('Tenant', $id));
            $this->em->getRelation($user, 'teams')->relateById($teamId);
        }
        $app = new Application(new ApplicationParams(noErrorHandler: true));
        $app->getContainer()->set('user', $this->em->getEntityById('User', $user->getId()));
        return $app;
    }

    public function testTenantDefinitionsSameCodeIsolationAndManagementGates(): void
    {
        $a = $this->tenant(); $b = $this->tenant();
        $code = 'advises_' . bin2hex(random_bytes(4));
        $pa = $this->predicate($a->getId(), $code);
        $pb = $this->predicate($b->getId(), $code);
        $this->assertNotSame($pa->getId(), $pb->getId());
        $app = $this->actor([$a->getId()], [$a->getId()]);
        $factory = $app->getContainer()->get('injectableFactory');
        $registry = $factory->create(PredicateRegistry::class);
        $this->assertArrayHasKey('tenant:' . $a->getId() . ':' . $code, $registry->schema($a->getId()));
        foreach ([fn () => $registry->schema($b->getId()), fn () => $factory->create(ServiceContainer::class)->get('RecordPredicate')->read($pb->getId())] as $read) {
            try { $read(); $this->fail('Foreign predicate read accepted.'); } catch (Forbidden|NotFound) { $this->addToAssertionCount(1); }
        }
        $query = $factory->create(SelectBuilderFactory::class)->create()->from('RecordPredicate')->withStrictAccessControl()->buildQueryBuilder()->build();
        $rows = $this->em->getRDBRepository('RecordPredicate')->clone($query)->find();
        foreach ($rows as $row) $this->assertSame($a->getId(), $row->get('tenantId'));
        $member = $this->actor([$a->getId()]);
        $memberFactory = $member->getContainer()->get('injectableFactory');
        $this->assertArrayHasKey('tenant:' . $a->getId() . ':' . $code, $memberFactory->create(PredicateRegistry::class)->schema($a->getId()));
        $this->assertFalse($member->getContainer()->get('acl')->checkScope('RecordPredicate', 'create'));
        try { $memberFactory->create(ServiceContainer::class)->get('RecordPredicate')->create((object) [
            'tenantId' => $a->getId(), 'code' => 'forbidden', 'name' => 'Forbidden',
        ]); $this->fail('Ordinary user created predicate.'); } catch (Forbidden) { $this->addToAssertionCount(1); }
        $both = $this->actor([$a->getId(), $b->getId()], [$a->getId()]);
        $access = $both->getContainer()->get('injectableFactory')->create(Tenancy::class);
        $access->assert($a->getId(), true);
        try { $access->assert($b->getId(), true); $this->fail('Tenant A admin managed tenant B.'); } catch (Forbidden) { $this->addToAssertionCount(1); }
    }

    public function testCustomPredicateLifecycleCacheAndHistoricalReview(): void
    {
        $tenant = $this->tenant();
        $predicate = $this->predicate($tenant->getId());
        $registry = $this->service(PredicateRegistry::class);
        $key = 'tenant:' . $tenant->getId() . ':' . $predicate->get('code');
        $this->assertArrayHasKey($key, $registry->schema($tenant->getId()));
        $predicate = $this->em->getEntityById('RecordPredicate', $predicate->getId());
        $predicate->set('name', 'Renamed ' . $predicate->get('code'));
        $this->em->saveEntity($predicate);
        $this->assertSame($predicate->get('name'), $registry->schema($tenant->getId())[$key]['label']);
        $project = $this->owned('CustomProject', ['name' => 'Evidence project', 'description' => 'Project advises Acme.'], $tenant->getId());
        $account = $this->owned('Account', ['name' => 'Custom Acme'], $tenant->getId());
        $overview = $this->service(Knowledge::class)->read('CustomProject', $project->getId());
        $input = (object) ['subjectType' => 'CustomProject', 'subjectId' => $project->getId(), 'objectType' => 'Account', 'objectId' => $account->getId(),
            'predicate' => $key, 'sourceRevisionId' => $overview['revision']['id'], 'evidenceQuote' => 'Project advises Acme.', 'idempotencyKey' => 'custom-claim-' . $project->getId()];
        $claim = $this->service(Relations::class)->submit($input);
        $this->assertSame($key, $claim['predicate']);
        $this->assertTrue($registry->schema($tenant->getId())[$key]['referenced']);
        $changed = $this->em->getEntityById('RecordPredicate', $predicate->getId());
        $changed->set('objectTypes', ['Contact']);
        try { $this->em->saveEntity($changed); $this->fail('Referenced schema changed.'); } catch (Conflict) { $this->addToAssertionCount(1); }
        $changed = $this->em->getEntityById('RecordPredicate', $predicate->getId());
        try { $this->em->removeEntity($changed); $this->fail('Referenced definition deleted.'); } catch (Conflict) { $this->addToAssertionCount(1); }
        $changed->set('isActive', false);
        $this->em->saveEntity($changed);
        $this->assertArrayNotHasKey($key, $registry->schema($tenant->getId()));
        $this->assertSame($key, $this->service(Relations::class)->list('CustomProject', $project->getId())['list'][0]['predicate']);
        $this->assertSame('confirmed', $this->service(Relations::class)->decide($claim['id'], 'confirmed')['status']);
        $input->idempotencyKey = 'inactive-new-claim-' . $project->getId();
        try { $this->service(Relations::class)->submit($input); $this->fail('Inactive new claim accepted.'); } catch (BadRequest) { $this->addToAssertionCount(1); }
    }

    public function testDefinitionCollisionsRetiredIdentitySchemasAndCrossTenantEvidence(): void
    {
        $tenant = $this->tenant();
        foreach (['works_at', 'employed_by'] as $reserved) {
            try { $this->predicate($tenant->getId(), $reserved); $this->fail('Built-in collision accepted.'); } catch (Conflict) { $this->addToAssertionCount(1); }
        }
        $predicate = $this->predicate($tenant->getId());
        $code = $predicate->get('code');
        $this->em->removeEntity($predicate);
        try { $this->predicate($tenant->getId(), $code); $this->fail('Retired canonical key reused.'); } catch (Conflict) { $this->addToAssertionCount(1); }
        try { $this->predicate($tenant->getId(), null, ['qualifierSchema' => (object) ['type' => 'object', '$ref' => 'https://example.test/schema']]);
            $this->fail('Unsupported schema accepted.'); } catch (BadRequest) { $this->addToAssertionCount(1); }
        $foreign = $this->tenant();
        $project = $this->owned('CustomProject', ['name' => 'Cross project', 'description' => 'Project advises Acme.'], $tenant->getId());
        $account = $this->owned('Account', ['name' => 'Foreign account'], $foreign->getId());
        $predicate = $this->predicate($tenant->getId());
        $overview = $this->service(Knowledge::class)->read('CustomProject', $project->getId());
        $input = (object) ['subjectType' => 'CustomProject', 'subjectId' => $project->getId(), 'objectType' => 'Account', 'objectId' => $account->getId(),
            'tenantId' => $tenant->getId(), 'predicate' => 'tenant:' . $tenant->getId() . ':' . $predicate->get('code'),
            'sourceRevisionId' => $overview['revision']['id'], 'evidenceQuote' => 'Project advises Acme.', 'idempotencyKey' => 'cross-tenant'];
        $this->expectException(BadRequest::class);
        $this->service(Relations::class)->submit($input);
    }

    public function testConcurrentDefinitionCreationReservesExactlyOneIdentity(): void
    {
        $tenant = $this->tenant();
        $code = 'parallel_' . bin2hex(random_bytes(4));
        $payload = ['tenantId' => $tenant->getId(), 'code' => $code, 'name' => 'Parallel ' . $code, 'inverseLabel' => 'Parallel inverse',
            'subjectTypes' => ['CustomProject'], 'objectTypes' => ['Account'], 'qualifierSchema' => QualifierSchema::shorthand([]), 'aliases' => [], 'isActive' => true];
        $this->workers('predicate', [base64_encode(json_encode($payload))]);
        $this->assertSame(1, $this->em->getRDBRepository('RecordPredicate')->where(['tenantId' => $tenant->getId(), 'code' => $code])->count());
    }

    public function testSchemaEditAndFirstUseSerializeWithoutChangingClaimSemantics(): void
    {
        $tenant = $this->tenant();
        $predicate = $this->predicate($tenant->getId());
        $project = $this->owned('CustomProject', ['name' => 'Race project', 'description' => 'Project advises Acme.'], $tenant->getId());
        $account = $this->owned('Account', ['name' => 'Race account'], $tenant->getId());
        $overview = $this->service(Knowledge::class)->read('CustomProject', $project->getId());
        $key = 'tenant:' . $tenant->getId() . ':' . $predicate->get('code');
        $proposal = ['subjectType' => 'CustomProject', 'subjectId' => $project->getId(), 'objectType' => 'Account', 'objectId' => $account->getId(),
            'predicate' => $key, 'sourceRevisionId' => $overview['revision']['id'], 'evidenceQuote' => 'Project advises Acme.', 'idempotencyKey' => 'first-use-race-' . $project->getId()];
        $this->workers('first-use', [base64_encode(json_encode(['predicateId' => $predicate->getId(), 'proposal' => $proposal]))]);
        $count = $this->em->getRDBRepository('RecordRelation')->where(['tenantId' => $tenant->getId(), 'predicate' => $key])->count();
        $this->assertContains($count, [0, 1]);
        $this->assertSame($count === 1 ? ['Account'] : ['Contact'], $this->em->getEntityById('RecordPredicate', $predicate->getId())->get('objectTypes'));
    }

    public static function tearDownAfterClass(): void { if (isset(self::$source)) chdir(self::$source); }
}

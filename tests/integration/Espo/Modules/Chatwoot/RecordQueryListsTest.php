<?php

declare(strict_types=1);

namespace tests\integration\Espo\Modules\Chatwoot;

use Espo\Core\Application;
use Espo\Core\Application\ApplicationParams;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Conflict;
use Espo\Core\InjectableFactory;
use Espo\Core\Select\SelectBuilderFactory;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Crypt;
use Espo\Core\Utils\File\Manager;
use Espo\Modules\Chatwoot\Tools\RecordQuery\Compiler;
use Espo\Modules\Chatwoot\Tools\RecordQuery\QueryError;
use Espo\Modules\Chatwoot\Tools\RecordQuery\Schema;
use Espo\Modules\FeatureCredits\Accounting\RecordId;
use Espo\Modules\FeatureCredits\Enrichment\ContactEnrichmentResults;
use Espo\Modules\FeatureCredits\Enrichment\FieldCache;
use Espo\Modules\FeatureCredits\Enrichment\RelationProvenance;
use Espo\Modules\FeatureCredits\Verification\IdentityInput;
use Espo\Modules\FeatureCredits\Verification\Kind;
use Espo\Modules\FeatureCredits\Verification\Operations;
use Espo\Modules\FeatureRecordKnowledge\Services\Relations;
use Espo\ORM\EntityManager;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Record query lists (`.any/.all/.none/.where/.count`, merged relationships, link `origin`, enrichmentResults) on a
 * disposable Espo runtime: real container, ORM, ACL and hooks. TEST_DATABASE_* must point to an empty database.
 *
 * The data deliberately contains links that cross workspaces (a native link and relation records between workspace A's
 * company and workspace B's contact, and an enrichment row filed under the wrong workspace): no query may match through
 * them, whoever runs it.
 */
class RecordQueryListsTest extends TestCase
{
    private static string $source;
    private static string $runtime;
    private static bool $seeded = false;
    /** @var array<string, string> */
    private static array $ids = [];
    private Application $app;
    private EntityManager $em;

    public static function setUpBeforeClass(): void
    {
        if (!getenv('TEST_DATABASE_NAME')) self::markTestSkipped('Requires an empty disposable CRM application database.');
        self::$source = getcwd();
        self::$runtime = getenv('RECORD_QUERY_TEST_RUNTIME') ?: '/tmp/opencode/record-query-' . bin2hex(random_bytes(4));
        $files = new Manager();
        $files->mkdir(self::$runtime);
        foreach (['application', 'vendor', 'client', 'install', 'html', 'public', 'index.php'] as $path) {
            if (!file_exists(self::$runtime . '/' . $path)) symlink(self::$source . '/' . $path, self::$runtime . '/' . $path);
        }
        foreach (['bootstrap.php', 'command.php', 'run-module-script.php'] as $path) copy(self::$source . '/' . $path, self::$runtime . '/' . $path);
        $files->copy(self::$source . '/custom', self::$runtime . '/custom', true);
        $files->mkdir(self::$runtime . '/data');
        chdir(self::$runtime);
        set_include_path(self::$runtime);
        require_once self::$source . '/install/core/Installer.php';
        $installer = new \Installer(new ApplicationParams(noErrorHandler: true));
        $config = ['database' => ['platform' => getenv('TEST_DATABASE_PLATFORM') ?: 'Mysql', 'host' => getenv('TEST_DATABASE_HOST'),
            'port' => getenv('TEST_DATABASE_PORT') ?: '', 'dbname' => getenv('TEST_DATABASE_NAME'),
            'user' => getenv('TEST_DATABASE_USER'), 'password' => getenv('TEST_DATABASE_PASSWORD')],
            'siteUrl' => 'http://127.0.0.1:8098', 'useCache' => false, 'isInstalled' => true, 'version' => '10.0.2'];
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
        $this->em = $this->app->getContainer()->getByClass(EntityManager::class);
        if (!self::$seeded) {
            $this->seed();
            self::$seeded = true;
        }
    }

    private function id(string $name): string
    {
        return self::$ids[$name];
    }

    private function pdo(): PDO
    {
        return $this->em->getPDO();
    }

    private function make(string $class): object
    {
        return $this->app->getContainer()->getByClass(InjectableFactory::class)->create($class);
    }

    /**
     * Workspace A: companies acme, beta (one plain contact), gamma (none), delta (a sócio and a plain contact), eps (only
     * a sócio). Sócios are linked as enrichment links them: a native link plus a relation record via enrichment.
     */
    private function seed(): void
    {
        foreach (['A', 'B'] as $name) {
            self::$ids["tenant$name"] = $this->em->createEntity('Tenant', ['name' => "Record query $name " . bin2hex(random_bytes(3)),
                'slug' => 'record-query-' . strtolower($name) . '-' . bin2hex(random_bytes(3))])->getId();
        }
        $a = $this->id('tenantA');
        foreach (['acme', 'beta', 'gamma', 'delta', 'eps'] as $name) {
            self::$ids[$name] = $this->em->createEntity('Account', ['name' => $name, 'tenantId' => $a])->getId();
        }
        foreach (['c1', 'c2', 'c3', 'c4', 'c5', 'c6', 'c7'] as $name) {
            self::$ids[$name] = $this->em->createEntity('Contact', ['firstName' => $name, 'lastName' => 'Fixture', 'tenantId' => $a])->getId();
        }
        self::$ids['x'] = $this->em->createEntity('Contact', ['firstName' => 'x', 'lastName' => 'Other workspace', 'tenantId' => $this->id('tenantB')])->getId();
        $relate = fn (string $account, string $contact) => $this->em->getRDBRepository('Account')
            ->getRelation($this->em->getEntityById('Account', $this->id($account)), 'contacts')->relate($this->em->getEntityById('Contact', $this->id($contact)));
        foreach ([['acme', 'c1'], ['acme', 'c2'], ['beta', 'c4'], ['delta', 'c5'], ['delta', 'c6'], ['eps', 'c7']] as [$account, $contact]) $relate($account, $contact);
        $relations = $this->make(Relations::class);
        $link = fn (string $contact, string $predicate, string $account, ?string $via) => $relations->submit((object) [
            'subjectType' => 'Contact', 'subjectId' => $this->id($contact), 'predicate' => $predicate,
            'objectType' => 'Account', 'objectId' => $this->id($account), 'tenantId' => $a,
            'idempotencyKey' => 'rq-' . $this->id($contact) . "-$predicate-" . $this->id($account),
        ], true, $via);
        $link('c1', 'owns', 'acme', Relations::VIA_ENRICHMENT);
        $link('c1', 'administers', 'acme', Relations::VIA_ENRICHMENT);
        $link('c2', 'administers', 'acme', Relations::VIA_ENRICHMENT);
        $link('c3', 'works_at', 'acme', null);
        $link('c5', 'owns', 'delta', Relations::VIA_ENRICHMENT);
        $link('c7', 'owns', 'eps', Relations::VIA_ENRICHMENT);

        // Planted cross-workspace links: workspace B's contact under workspace A's company, every way the data allows.
        $this->pdo()->prepare('INSERT INTO account_contact (account_id, contact_id, deleted) VALUES (?, ?, 0)')->execute([$this->id('acme'), $this->id('x')]);
        foreach ([$a, $this->id('tenantB')] as $tenant) {
            $this->pdo()->prepare("INSERT INTO record_relation (id, tenant_id, subject_type, subject_id, predicate, object_type, object_id,
                qualifiers, status, origin, via, submission_key, submission_hash, created_at, deleted)
                VALUES (?, ?, 'Contact', ?, 'builtin:owns', 'Account', ?, '{}', 'confirmed', 'manual', 'enrichment', ?, ?, ?, 0)")
                ->execute([RecordId::generate(), $tenant, $this->id('x'), $this->id('acme'), bin2hex(random_bytes(32)), bin2hex(random_bytes(32)), gmdate('Y-m-d H:i:s')]);
        }

        // enrichmentResults rows, as the projection writes them.
        $row = fn (string $contact, string $type, bool $valid, bool $added, ?string $tenant = null) => $this->pdo()->prepare(
            'INSERT INTO contact_enrichment_result (id, tenant_id, contact_id, type, value_hash, valid, added, found_at, deleted)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0)')->execute([RecordId::generate(), $tenant ?? $a, $this->id($contact), $type,
            bin2hex(random_bytes(32)), (int) $valid, (int) $added, gmdate('Y-m-d H:i:s')]);
        $row('c1', 'email', true, true);
        $row('c1', 'phone', true, false);
        $row('c2', 'email', true, true);
        $row('c4', 'phone', false, false);
        $row('c5', 'email', true, true);
        $row('c5', 'phone', true, true);
        $row('c7', 'email', false, false);
        // x's own row says nothing passed; a row filed under workspace A claims it did and must never count.
        $row('x', 'email', false, false, $this->id('tenantB'));
        $row('x', 'email', true, true, $a);

        $login = 'rq-admin-' . bin2hex(random_bytes(3)) . '@example.test';
        $admin = $this->em->createEntity('User', ['userName' => $login, 'emailAddress' => $login, 'type' => 'admin', 'isActive' => true]);
        $this->em->getRDBRepository('Tenant')->getRelation($this->em->getEntityById('Tenant', $a), 'users')->relate($admin);
        self::$ids['admin'] = $admin->getId();
    }

    private function factoryFor(string $userId): InjectableFactory
    {
        $app = new Application(new ApplicationParams(noErrorHandler: true));
        $app->getContainer()->set('user', $this->em->getEntityById('User', $userId));
        return $app->getContainer()->getByClass(InjectableFactory::class);
    }

    /**
     * Fixture names of $type records the query matches, within the fixtures named.
     *
     * @param list<string> $among
     * @return list<string>
     */
    private function matching(string $type, string $query, array $among, ?InjectableFactory $factory = null): array
    {
        $factory ??= $this->factoryFor($this->id('admin'));
        $where = $factory->create(Compiler::class)->compile($type, $query);
        $ids = array_map(fn (string $name) => $this->id($name), $among);
        $select = $factory->create(SelectBuilderFactory::class)->create()->from($type)->withStrictAccessControl()->buildQueryBuilder()
            ->select(['id'])->where($where)->where(['id' => $ids])->build();
        $found = array_map(fn ($entity) => $entity->getId(), iterator_to_array($this->em->getRDBRepository($type)->clone($select)->find()));
        $names = array_values(array_filter($among, fn (string $name) => in_array($this->id($name), $found, true)));
        sort($names);
        return $names;
    }

    private function refused(string $type, string $query, ?InjectableFactory $factory = null): string
    {
        try {
            ($factory ?? $this->factoryFor($this->id('admin')))->create(Compiler::class)->compile($type, $query);
        } catch (QueryError $error) {
            return $error->errorCode;
        }
        $this->fail("Compiled: $query");
    }

    private const COMPANIES = ['acme', 'beta', 'gamma', 'delta', 'eps'];

    public function testQuantifiersOverMergedSocios(): void
    {
        $socios = 'contacts.where(origin = "enrichment")';
        $both = 'enrichmentResults.any(type = "email" AND valid = true) AND enrichmentResults.any(type = "phone" AND valid = true)';
        // acme: c1 has both, c2 has no phone. delta: c5 has both (c6 is not a sócio). eps: c7 has nothing valid.
        $this->assertSame(['delta'], $this->matching('Account', "$socios.all($both)", self::COMPANIES));
        $this->assertSame(['acme', 'delta'], $this->matching('Account', "$socios.any($both)", self::COMPANIES));
        $this->assertSame(['beta', 'eps', 'gamma'], $this->matching('Account', "$socios.none($both)", self::COMPANIES));
        // ALL needs at least one row in scope: companies without sócios never match.
        $this->assertSame(['acme', 'delta', 'eps'], $this->matching('Account', "$socios.all()", self::COMPANIES));
    }

    public function testValidResultsAllAddedNeedsAtLeastOneValidResult(): void
    {
        // c7 has no valid result, so "every valid result added" fails for it: all() requires one row in scope.
        $query = 'contacts.where(origin = "enrichment").all(enrichmentResults.where(valid = true).all(added = true))';
        $this->assertSame(['delta'], $this->matching('Account', $query, self::COMPANIES));
        $this->assertSame(['acme', 'delta'], $this->matching('Account',
            'contacts.where(origin = "enrichment").any(enrichmentResults.where(valid = true).any(added = true))', self::COMPANIES));
    }

    public function testOriginIsALinkDetailAndAPairCountsOnceThroughItsRelation(): void
    {
        // A native link with a relation record behind it takes the relation's origin; only plain native links are empty.
        $this->assertSame(['eps'], $this->matching('Account', 'contacts.all(origin = "enrichment")', self::COMPANIES));
        $this->assertSame(['acme', 'beta', 'delta'], $this->matching('Account', 'contacts.any(origin != "enrichment")', self::COMPANIES));
        $this->assertSame(['beta', 'delta'], $this->matching('Account', 'contacts.any(origin IS EMPTY)', self::COMPANIES));
        $this->assertSame(['acme'], $this->matching('Account', 'contacts.any(origin = "manual")', self::COMPANIES));
        $this->assertSame(['acme'], $this->matching('Account', 'contacts.any(origin IN ("manual", "assistant") AND firstName = "c3")', self::COMPANIES));
        // Mixed with OR: the link detail and the contact condition apply to the same row.
        $this->assertSame(['acme', 'eps'], $this->matching('Account', 'contacts.any(origin = "manual" OR firstName = "c7")', self::COMPANIES));
        $this->assertSame('bad_value', $this->refused('Account', 'contacts.any(origin = "imported")'));
        $this->assertSame('bad_operator', $this->refused('Account', 'contacts.any(origin ~ "enr")'));
    }

    public function testCount(): void
    {
        $this->assertSame(['acme'], $this->matching('Account', 'contacts.count() >= 3', self::COMPANIES));
        $this->assertSame(['gamma'], $this->matching('Account', 'contacts.count() = 0', self::COMPANIES));
        $this->assertSame(['beta', 'eps', 'gamma'], $this->matching('Account', 'contacts.count() < 2', self::COMPANIES));
        $this->assertSame(['acme'], $this->matching('Account', 'contacts.where(origin = "enrichment").count() = 2', self::COMPANIES));
        $this->assertSame(['beta', 'gamma'], $this->matching('Account', 'contacts.count(origin = "enrichment") = 0', self::COMPANIES));
        $this->assertSame(['c5'], $this->matching('Contact', 'enrichmentResults.count(valid = true AND added = true) >= 2', ['c1', 'c2', 'c4', 'c5', 'c7']));
    }

    public function testShorthandsAndOlderQueriesStillWork(): void
    {
        $this->assertSame(['acme', 'beta', 'delta', 'eps'], $this->matching('Account', 'contacts()', self::COMPANIES));
        $this->assertSame(['acme', 'beta', 'delta', 'eps'], $this->matching('Account', 'contacts IS NOT EMPTY', self::COMPANIES));
        $this->assertSame(['gamma'], $this->matching('Account', 'contacts IS EMPTY', self::COMPANIES));
        $this->assertSame(['acme'], $this->matching('Account', 'related.Contact(firstName = "c3")', self::COMPANIES));
        // The relation-only contact now counts in contacts too.
        $this->assertSame(['acme'], $this->matching('Account', 'contacts.firstName = "c3"', self::COMPANIES));
        $this->assertSame(['c1', 'c2', 'c3'], $this->matching('Contact', 'accounts.any(name = "acme")', ['c1', 'c2', 'c3', 'c4']));
    }

    public function testCrossWorkspaceLinksNeverMatch(): void
    {
        $x = $this->id('x');
        foreach (["contacts.any(id = \"$x\")", "contacts.where(id = \"$x\").all()", "contacts.count(id = \"$x\") >= 1",
            "related.Contact(id = \"$x\")", "contacts.any(origin = \"enrichment\" AND id = \"$x\")", "NOT contacts.none(id = \"$x\")"] as $query) {
            $this->assertSame([], $this->matching('Account', $query, self::COMPANIES), $query);
        }
        // Counting never includes x either: acme has c1, c2, c3.
        $this->assertSame([], $this->matching('Account', 'contacts.count() >= 4', self::COMPANIES));
        // From the other side, x is linked to no company in its own workspace.
        $this->assertSame([], $this->matching('Contact', 'accounts.any()', ['x']));
        // The enrichment row filed under workspace A never counts for x; its own row says nothing passed.
        $this->assertSame([], $this->matching('Contact', 'enrichmentResults.any(valid = true)', ['x']));
        $this->assertSame(['x'], $this->matching('Contact', 'enrichmentResults.all(valid = false)', ['x']));
    }

    public function testInjectionStaysData(): void
    {
        $this->assertSame([], $this->matching('Account', 'name = "acme\' OR \'1\'=\'1"', self::COMPANIES));
        $this->assertSame([], $this->matching('Account', 'contacts.any(firstName = "c1\\" OR 1=1 -- ")', self::COMPANIES));
        $this->assertSame(['acme'], $this->matching('Account', 'name ~ "acm"', self::COMPANIES));
        $this->assertSame('unknown_field', $this->refused('Account', 'contacts.any(first_name = "c1")'));
        $this->assertSame('unknown_link', $this->refused('Account', 'nonexistentThings.any()'));
        $this->assertSame('unexpected_character', $this->refused('Account', 'name = "a"; DROP TABLE account'));
        $this->assertSame(5, (int) $this->pdo()->query("SELECT COUNT(*) FROM account WHERE name IN ('acme','beta','gamma','delta','eps')")->fetchColumn());
    }

    public function testBudgetBoundsTheSql(): void
    {
        $this->assertSame('too_complex', $this->refused('Account', implode(' OR ', array_fill(0, 41, 'name = "a"'))));
        $this->assertSame('too_complex', $this->refused('Account', implode(' AND ', array_fill(0, 9, 'contacts.any()'))));
        $this->assertSame('too_deep', $this->refused('Account', 'contacts.any(accounts.any(contacts.any(accounts.any())))'));
    }

    private function limitedUser(array $fieldData = [], bool $enrichment = true): string
    {
        $role = $this->em->createEntity('Role', ['name' => 'Record query limited ' . bin2hex(random_bytes(3)),
            'useEnrichmentCreditsPermission' => $enrichment ? 'yes' : 'no', 'data' => (object) [
            'Account' => (object) ['read' => 'all', 'create' => 'no', 'edit' => 'no', 'delete' => 'no'],
            'Contact' => (object) ['read' => 'own', 'create' => 'no', 'edit' => 'no', 'delete' => 'no'],
        ], 'fieldData' => (object) $fieldData]);
        $login = 'rq-limited-' . bin2hex(random_bytes(3)) . '@example.test';
        $user = $this->em->createEntity('User', ['userName' => $login, 'emailAddress' => $login, 'type' => 'regular', 'isActive' => true]);
        $this->em->getRelation($user, 'roles')->relate($role);
        $this->em->getRelation($this->em->getEntityById('Tenant', $this->id('tenantA')), 'users')->relate($user);
        foreach ($this->em->getRelation($user, 'teams')->find() as $team) {
            foreach ($this->em->getRelation($team, 'roles')->find() as $teamRole) $this->em->getRelation($team, 'roles')->unrelate($teamRole);
            $this->em->getRelation($team, 'roles')->relate($role);
        }
        return $user->getId();
    }

    public function testContactsTheUserCannotOpenNeverCount(): void
    {
        $user = $this->limitedUser();
        foreach (['c1', 'c5'] as $name) {
            $contact = $this->em->getEntityById('Contact', $this->id($name));
            $contact->set('assignedUserId', $user);
            $this->em->saveEntity($contact);
        }
        $factory = $this->factoryFor($user);
        $c2 = $this->id('c2');
        // c2 belongs to acme but this user cannot open it: not matched, not counted, and not a reason for all() to fail.
        $this->assertSame([], $this->matching('Account', "contacts.any(id = \"$c2\")", self::COMPANIES, $factory));
        $this->assertSame(['acme', 'delta'], $this->matching('Account', 'contacts.count() = 1', ['acme', 'delta'], $factory));
        $both = 'enrichmentResults.any(type = "email" AND valid = true) AND enrichmentResults.any(type = "phone" AND valid = true)';
        $this->assertSame(['acme', 'delta'], $this->matching('Account', "contacts.where(origin = \"enrichment\").all($both)", ['acme', 'delta'], $factory));
    }

    public function testRelationsBackedByADocumentCountOnlyForWhoCanReadIt(): void
    {
        $zeta = $this->em->createEntity('Account', ['name' => 'zeta', 'tenantId' => $this->id('tenantA')])->getId();
        self::$ids['zeta'] = $zeta;
        $document = RecordId::generate();
        // Knowledge about c2, which the limited user cannot open.
        $this->insertRequired('document', ['id' => $document, 'knowledge_record_type' => 'Contact',
            'knowledge_record_id' => $this->id('c2'), 'deleted' => 0]);
        $relation = RecordId::generate();
        $this->pdo()->prepare("INSERT INTO record_relation (id, tenant_id, subject_type, subject_id, predicate, object_type, object_id,
            qualifiers, status, origin, source_document_id, submission_key, submission_hash, created_at, deleted)
            VALUES (?, ?, 'Contact', ?, 'builtin:works_at', 'Account', ?, '{}', 'confirmed', 'assistant', ?, ?, 'h', ?, 0)")
            ->execute([$relation, $this->id('tenantA'), $this->id('c1'), $zeta, $document, bin2hex(random_bytes(32)), gmdate('Y-m-d H:i:s')]);
        $user = $this->limitedUser();
        $contact = $this->em->getEntityById('Contact', $this->id('c1'));
        $contact->set('assignedUserId', $user);
        $this->em->saveEntity($contact);
        $this->assertSame(['zeta'], $this->matching('Account', 'contacts.any(origin = "assistant")', ['zeta']));
        $this->assertSame([], $this->matching('Account', 'contacts.any()', ['zeta'], $this->factoryFor($user)));
        $this->pdo()->prepare('DELETE FROM record_relation WHERE id = ?')->execute([$relation]);
    }

    public function testHiddenFieldsAndEnrichmentStayHidden(): void
    {
        $factory = $this->factoryFor($this->limitedUser(['Contact' => (object) [
            'emailEnrichmentResult' => (object) ['read' => 'no', 'edit' => 'no'],
            'lastName' => (object) ['read' => 'no', 'edit' => 'no'],
        ]]));
        $this->assertSame('unknown_link', $this->refused('Account', 'contacts.any(enrichmentResults.any())', $factory));
        $this->assertSame('unknown_field', $this->refused('Account', 'contacts.any(lastName = "Fixture")', $factory));
        $this->assertSame('unknown_field', $this->refused('Contact', 'emailEnrichmentResult = "found"', $factory));
        $describe = $factory->create(Schema::class)->describe('Contact');
        $this->assertNotContains('enrichmentResults', array_column($describe->links, 'name'));
        $this->assertNotContains('lastName', array_column($describe->fields, 'name'));
    }

    public function testHiddenRelationshipFieldsCannotBeFollowed(): void
    {
        $factory = $this->factoryFor($this->limitedUser(['Contact' => (object) [
            'accounts' => (object) ['read' => 'no', 'edit' => 'no'],
            'account' => (object) ['read' => 'no', 'edit' => 'no'],
        ]]));
        $this->assertSame('unknown_link', $this->refused('Contact', 'accounts.any(name = "acme")', $factory));
        $this->assertSame('unknown_link', $this->refused('Contact', 'accounts.count() >= 1', $factory));
        $this->assertSame('unknown_field', $this->refused('Contact', 'account = "acme"', $factory));
        $this->assertSame('unknown_link', $this->refused('Contact', 'account.name = "acme"', $factory));
    }

    public function testEnrichmentResultsNeedTheEnrichmentPermission(): void
    {
        $user = $this->limitedUser([], false);
        $this->assertSame('unknown_link', $this->refused('Contact', 'enrichmentResults.any(valid = true)', $this->factoryFor($user)));
        // A job builds the list for this user while its container runs as system: the gates are still the user's.
        $compiler = $this->app->getContainer()->getByClass(InjectableFactory::class)
            ->createWith(Compiler::class, ['user' => $this->em->getEntityById('User', $user)]);
        try {
            $compiler->compile('Contact', 'enrichmentResults.any(valid = true)');
            $this->fail('System gates applied to a user\'s query.');
        } catch (QueryError $error) {
            $this->assertSame('unknown_link', $error->errorCode);
        }
    }

    public function testCompiledSizeIsBounded(): void
    {
        // Short texts that compile into hundreds of sub-selects: merged lists read three places, .all compiles its scope twice.
        $this->assertSame('too_complex', $this->refused('Account',
            'contacts.where(accounts.where(contacts.where(firstName = "a").all(firstName = "b")).all(name = "c")).all(firstName = "d")'));
        $this->assertSame('too_complex', $this->refused('Account',
            'contacts.where(accounts.where(' . implode(' OR ', array_fill(0, 38, 'contacts IS NOT EMPTY')) . ').all(name = "c")).all(firstName = "d")'));
        $this->assertSame('too_complex', $this->refused('Account', implode(' OR ', array_fill(0, 30, 'contacts IS NOT EMPTY'))));
        // The queries people write stay well inside.
        $both = 'enrichmentResults.any(type = "email" AND valid = true) AND enrichmentResults.any(type = "phone" AND valid = true)';
        $this->factoryFor($this->id('admin'))->create(Compiler::class)->compile('Account',
            "contacts.where(origin = \"enrichment\").all($both) AND opportunities.any(name ~ \"x\") AND contacts.where(origin = \"enrichment\").all(enrichmentResults.where(valid = true).all(added = true))");
        $this->addToAssertionCount(1);
    }

    public function testDescribeOffersListsWithTheirLinkDetails(): void
    {
        $describe = $this->factoryFor($this->id('admin'))->create(Schema::class)->describe('Account');
        $contacts = array_values(array_filter($describe->links, fn (array $link) => $link['name'] === 'contacts'))[0];
        $this->assertTrue($contacts['many']);
        $this->assertSame(['manual', 'assistant', 'enrichment'], array_column($contacts['linkFields'][0]['options'], 'value'));
        $results = array_values(array_filter($this->factoryFor($this->id('admin'))->create(Schema::class)->describe('Contact')->links,
            fn (array $link) => $link['name'] === 'enrichmentResults'))[0];
        $this->assertSame(['type', 'valid', 'added'], array_column($results['schema']['fields'], 'name'));
    }

    public function testProvenanceIsSetOnlyByTheServiceAndNeverChanges(): void
    {
        $base = ['tenantId' => $this->id('tenantA'), 'subjectType' => 'Contact', 'subjectId' => $this->id('c4'), 'predicate' => 'works_at',
            'objectType' => 'Account', 'objectId' => $this->id('beta'), 'origin' => 'manual', 'status' => 'confirmed'];
        try {
            $this->em->createEntity('RecordRelation', [...$base, 'via' => 'enrichment', 'submissionKey' => bin2hex(random_bytes(32)), 'submissionHash' => 'x']);
            $this->fail('A client set provenance.');
        } catch (BadRequest) {
            $this->addToAssertionCount(1);
        }
        try {
            $this->make(Relations::class)->submit((object) ['subjectType' => 'Contact', 'subjectId' => $this->id('c4'), 'predicate' => 'works_at',
                'objectType' => 'Account', 'objectId' => $this->id('beta'), 'idempotencyKey' => 'rq-assistant-via'], false, Relations::VIA_ENRICHMENT);
            $this->fail('An assistant proposal carried provenance.');
        } catch (BadRequest) {
            $this->addToAssertionCount(1);
        }
        $relation = $this->em->getRDBRepository('RecordRelation')->where(['subjectId' => $this->id('c3'), 'objectId' => $this->id('acme')])->findOne();
        $relation->set('via', 'enrichment');
        $this->expectException(Conflict::class);
        $this->em->saveEntity($relation);
    }

    public function testBackfillMarksOnlyTheLinksAnApplicationCreated(): void
    {
        $actor = $this->id('admin');
        $purchase = RecordId::generate();
        $made = RecordId::generate();
        $reused = RecordId::generate();
        foreach ([[$made, hash('sha256', "$actor:enrichment:$purchase:0")], [$reused, hash('sha256', "$actor:someone-else:1")]] as [$id, $key]) {
            $this->pdo()->prepare("INSERT INTO record_relation (id, tenant_id, subject_type, subject_id, predicate, object_type, object_id,
                qualifiers, status, origin, submission_key, submission_hash, created_at, deleted)
                VALUES (?, ?, 'Contact', ?, 'builtin:owns', 'Account', ?, '{}', 'confirmed', 'manual', ?, ?, ?, 0)")
                ->execute([$id, $this->id('tenantA'), $this->id('c6'), $this->id('delta'), $key, 'h', gmdate('Y-m-d H:i:s')]);
        }
        $columns = ['id' => $purchase, 'tenant_id' => $this->id('tenantA'), 'values_ciphertext' => $this->make(Crypt::class)->encrypt('{}'),
            'application_receipt' => json_encode(['purchaseId' => $purchase, 'tenantId' => $this->id('tenantA'), 'actorId' => $actor,
                'relationIds' => [$made, $reused]])];
        $this->insertRequired('enrichment_purchase', $columns);
        RelationProvenance::backfill($this->pdo());
        RelationProvenance::backfill($this->pdo());
        $via = fn (string $id) => $this->pdo()->query("SELECT via FROM record_relation WHERE id = " . $this->pdo()->quote($id))->fetchColumn();
        $this->assertSame('enrichment', $via($made));
        $this->assertNull($via($reused));
        $this->pdo()->exec("DELETE FROM record_relation WHERE id IN ('$made', '$reused')");
        $this->pdo()->exec("DELETE FROM enrichment_purchase WHERE id = '$purchase'");
    }

    /** Inserts a row with placeholder values for every required column the test does not care about. */
    private function insertRequired(string $table, array $values): void
    {
        $q = $this->pdo()->prepare('SELECT column_name, data_type FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ?
            AND is_nullable = \'NO\' AND column_default IS NULL');
        $q->execute([$table]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $column) {
            $name = $column['column_name'] ?? $column['COLUMN_NAME'];
            $type = strtolower($column['data_type'] ?? $column['DATA_TYPE']);
            if (array_key_exists($name, $values)) continue;
            $values[$name] = match (true) {
                str_contains($type, 'date') || str_contains($type, 'time') => gmdate('Y-m-d H:i:s'),
                str_contains($type, 'int') || str_contains($type, 'dec') => 0,
                $name === 'id' || str_ends_with($name, '_id') => RecordId::generate(),
                default => substr(bin2hex(random_bytes(8)), 0, 16),
            };
        }
        $this->pdo()->prepare('INSERT INTO ' . $table . ' (' . implode(', ', array_keys($values)) . ') VALUES (' .
            implode(', ', array_fill(0, count($values), '?')) . ')')->execute(array_values($values));
    }

    public function testProjectionFollowsPurchasesChecksRetentionAndChannels(): void
    {
        $tenant = $this->id('tenantA');
        $contact = $this->em->createEntity('Contact', ['firstName' => 'Projected', 'lastName' => 'Person', 'tenantId' => $tenant])->getId();
        $crypt = $this->make(Crypt::class);
        $key = IdentityInput::hmacKey((string) $this->app->getContainer()->getByClass(Config::class)->get('cryptKey', 'feature-credits-default-key'));
        $purchase = function (string $product, array $values, ?string $quoteTenant = null, bool $tamper = false) use ($tenant, $contact, $crypt): void {
            $quote = RecordId::generate();
            $attempt = RecordId::generate();
            $this->insertRequired('enrichment_quote', ['id' => $quote, 'tenant_id' => $quoteTenant ?? $tenant, 'target_type' => 'Contact',
                'target_id' => $contact, 'snapshot' => '{}', 'deleted' => 0]);
            $hash = FieldCache::resultHash($quoteTenant ?? $tenant, $quote, $attempt, 'apollo', $product, $values, []);
            $this->insertRequired('enrichment_attempt', ['id' => $attempt, 'tenant_id' => $quoteTenant ?? $tenant, 'quote_id' => $quote,
                'product' => $product, 'provider' => 'apollo', 'state' => 'matched', 'purchased_at' => gmdate('Y-m-d H:i:s'),
                'result_ciphertext' => $crypt->encrypt(json_encode($values)), 'result_hash' => $tamper ? str_repeat('0', 64) : $hash, 'deleted' => 0]);
        };
        $purchase('email', ['Ana@Example.com', 'not an email']);
        $purchase('phone', ['+55 11 98765-4321']);
        $purchase('email', ['tampered@example.com'], null, true);
        $purchase('email', ['elsewhere@example.com'], $this->id('tenantB'));
        $check = function (string $kind, string $value, string $outcome, string $retention) use ($tenant, $contact, $key, $crypt): string {
            $id = RecordId::generate();
            $this->insertRequired('phone_verification_operation', ['id' => $id, 'tenant_id' => $tenant, 'contact_id' => $contact,
                'verification_kind' => $kind, 'state' => 'completed', 'outcome' => $outcome, 'completed_at' => gmdate('Y-m-d H:i:s'),
                'subject_hash' => hash_hmac('sha256', Operations::hash([$kind, $tenant, $contact, $value]), $key),
                'retention_expires_at' => $retention, 'evidence_ciphertext' => $crypt->encrypt('{}'), 'deleted' => 0]);
            return $id;
        };
        $email = $check(Kind::EMAIL, 'ana@example.com', 'deliverable', gmdate('Y-m-d H:i:s', time() + 86400));
        $check(Kind::WHATSAPP, '+5511987654321', 'registered', gmdate('Y-m-d H:i:s', time() + 86400));
        $this->em->createEntity('ContactChannelIdentity', ['contactId' => $contact, 'tenantId' => $tenant, 'channelType' => 'email', 'sourceId' => 'ana@example.com']);
        $results = $this->make(ContactEnrichmentResults::class);
        $results->refresh($contact);
        $rows = fn () => $this->pdo()->query('SELECT type, valid, added FROM contact_enrichment_result WHERE contact_id = ' . $this->pdo()->quote($contact) . ' ORDER BY type')
            ->fetchAll(PDO::FETCH_ASSOC);
        $this->assertEquals([['type' => 'email', 'valid' => 1, 'added' => 1], ['type' => 'phone', 'valid' => 1, 'added' => 0]], $rows());
        // The hook: adding the number as a WhatsApp channel marks the phone added.
        $this->em->createEntity('ContactChannelIdentity', ['contactId' => $contact, 'tenantId' => $tenant, 'channelType' => 'whatsapp', 'sourceId' => '+5511987654321']);
        $this->assertEquals([['type' => 'email', 'valid' => 1, 'added' => 1], ['type' => 'phone', 'valid' => 1, 'added' => 1]], $rows());
        // A check past its 90-day retention no longer makes the value valid.
        $this->pdo()->prepare('UPDATE phone_verification_operation SET retention_expires_at = ? WHERE id = ?')->execute([gmdate('Y-m-d H:i:s', time() - 60), $email]);
        $results->refresh($contact);
        $this->assertEquals([['type' => 'email', 'valid' => 0, 'added' => 1], ['type' => 'phone', 'valid' => 1, 'added' => 1]], $rows());
        // No row ever holds a value.
        $dump = json_encode($this->pdo()->query('SELECT * FROM contact_enrichment_result WHERE contact_id = ' . $this->pdo()->quote($contact))->fetchAll(PDO::FETCH_ASSOC));
        $this->assertStringNotContainsString('ana', $dump);
        $this->assertStringNotContainsString('987654321', $dump);
        // Removing the Contact removes its rows. The fixture receipts go first (raw, so no refresh runs): erasure validates
        // real quote bindings, which these placeholders do not have.
        $this->pdo()->prepare('DELETE a FROM enrichment_attempt a JOIN enrichment_quote q ON q.id = a.quote_id WHERE q.target_id = ?')->execute([$contact]);
        $this->pdo()->prepare('DELETE FROM enrichment_quote WHERE target_id = ?')->execute([$contact]);
        $this->assertCount(2, $rows());
        $this->em->removeEntity($this->em->getEntityById('Contact', $contact));
        $this->assertSame([], $rows());
    }
}

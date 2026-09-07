<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\Chatwoot\Security;

use Espo\Core\AclManager;
use Espo\Core\InjectableFactory;
use Espo\Core\Select\AccessControl\Filter;
use Espo\Core\Select\AccessControl\FilterFactory;
use Espo\Core\Select\SearchParams;
use Espo\Core\Select\SelectBuilder as AccessSelectBuilder;
use Espo\Core\Select\SelectBuilderFactory;
use Espo\Entities\User;
use Espo\Modules\Chatwoot\Classes\Select\Attachment\OpportunityAccess as AttachmentApplier;
use Espo\Modules\Chatwoot\Classes\Select\Note\OpportunityEventAccess as NoteApplier;
use Espo\Modules\Chatwoot\Classes\Select\Opportunity\AccessControlFilters\Tenant;
use Espo\Modules\Chatwoot\Services\OpportunityStreamEvents;
use Espo\Modules\Chatwoot\Tools\Stream\OpportunityAccess;
use Espo\Modules\Chatwoot\Tools\Stream\OpportunityAttachmentAccess;
use Espo\Modules\Chatwoot\Tools\Stream\OpportunityEventAccess;
use Espo\Modules\Chatwoot\Tools\Stream\QueryHelper;
use Espo\Modules\Global\Tools\Tenant\UserTenantResolver;
use Espo\ORM\BaseEntity;
use Espo\ORM\EntityFactory;
use Espo\ORM\EntityManager;
use Espo\ORM\Metadata;
use Espo\ORM\MetadataDataProvider;
use Espo\ORM\Query\SelectBuilder;
use Espo\ORM\QueryBuilder;
use Espo\ORM\QueryComposer\MysqlQueryComposer;
use Espo\ORM\QueryComposer\PostgresqlQueryComposer;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Both production SQL dialect composers execute against SQLite, never a tenant database. */
class TenantPredicatesSqlTest extends TestCase
{
    private PDO $pdo;
    private array $composers;
    private User $user;
    private UserTenantResolver $tenants;
    private EntityManager $em;
    private AclManager $acl;
    private SelectBuilderFactory $selects;
    private OpportunityAccess $parents;
    private OpportunityEventAccess $events;
    private OpportunityAttachmentAccess $attachments;
    private array $tenantIds = ['tenant-a'];
    private array $deniedScopes = [];
    private array $builtScopes = [];
    private array $streamFilters = [];
    private string $streamLevel = 'all';
    private bool $portal = false;
    private bool $admin = false;

    protected function setUp(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('PDO SQLite is needed for the disposable SQL fixture.');
        }
        $this->pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $tables = [
            'Opportunity' => ['id', 'tenantId', 'assignedUserId', 'teamId', 'accountId', 'contactId', 'readable', 'deleted'],
            'Note' => ['id', 'parentType', 'parentId', 'type', 'relatedType', 'relatedId', 'createdById', 'number', 'isPinned', 'isInternal', 'readable', 'deleted'],
            'Attachment' => ['id', 'parentType', 'parentId', 'relatedType', 'relatedId', 'createdById', 'deleted'],
            'ChatwootConversation' => ['id', 'readable', 'deleted'],
            'Task' => ['id', 'readable', 'deleted'],
            'Meeting' => ['id', 'readable', 'deleted'],
            'Call' => ['id', 'readable', 'deleted'],
        ];
        $defs = [];
        foreach ($tables as $type => $fields) {
            $columns = [];
            foreach ($fields as $field) {
                $numeric = in_array($field, ['number', 'isPinned', 'isInternal', 'readable', 'deleted'], true);
                $columns[] = $this->sqlName($field) . ($numeric ? ' INTEGER DEFAULT 0' : ' TEXT');
                $defs[$type]['attributes'][$field] = ['type' => $numeric ? 'int' : 'varchar'];
            }
            $this->pdo->exec('CREATE TABLE ' . $this->sqlName($type) . ' (' . implode(', ', $columns) . ')');
        }
        $provider = $this->createMock(MetadataDataProvider::class);
        $provider->method('get')->willReturn($defs);
        $metadata = new Metadata($provider);
        $entities = $this->createMock(EntityFactory::class);
        $entities->method('create')->willReturnCallback(fn ($type) => new BaseEntity($type, $defs[$type]));
        $this->composers = [
            'mysql' => new MysqlQueryComposer($this->pdo, $entities, $metadata),
            'postgresql' => new PostgresqlQueryComposer($this->pdo, $entities, $metadata),
        ];

        $this->user = $this->createMock(User::class);
        $this->user->method('getId')->willReturn('agent');
        $this->user->method('isAdmin')->willReturnCallback(fn () => $this->admin);
        $this->user->method('isPortal')->willReturnCallback(fn () => $this->portal);
        $this->user->method('isRegular')->willReturnCallback(fn () => !$this->portal && !$this->admin);
        $this->tenants = $this->createMock(UserTenantResolver::class);
        $this->tenants->method('resolveTenantIds')->with($this->user)->willReturnCallback(fn () => $this->tenantIds);
        $this->acl = $this->createMock(AclManager::class);
        $this->acl->method('checkScope')->willReturnCallback(fn ($user, $scope, $action) => !in_array("$scope:$action", $this->deniedScopes, true));
        $this->acl->method('getLevel')->with($this->user, 'Opportunity', 'stream')->willReturnCallback(fn () => $this->streamLevel);
        $this->em = $this->createMock(EntityManager::class);
        $this->em->method('getQueryBuilder')->willReturn(new QueryBuilder());
        $factory = $this->createMock(InjectableFactory::class);
        $this->selects = $this->createMock(SelectBuilderFactory::class);
        $factory->method('createWith')->with(SelectBuilderFactory::class, ['user' => $this->user])->willReturn($this->selects);
        $this->selects->method('create')->willReturnCallback(function () {
            $scope = null;
            $strict = false;
            $builder = $this->createMock(AccessSelectBuilder::class);
            $builder->method('from')->willReturnCallback(function ($type) use (&$scope, $builder) {
                $scope = $type;
                return $builder;
            });
            $builder->method('withStrictAccessControl')->willReturnCallback(function () use (&$strict, $builder) {
                $strict = true;
                return $builder;
            });
            $builder->method('withComplexExpressionsForbidden')->willReturnSelf();
            $builder->method('withWherePermissionCheck')->willReturnSelf();
            $builder->method('withSearchParams')->willReturnCallback(function ($params) use ($builder) {
                self::assertNull($params->getOffset(), 'Stream base queries must not paginate before applying ACL.');
                self::assertNull($params->getMaxSize());
                return $builder;
            });
            $builder->method('buildQueryBuilder')->willReturnCallback(function () use (&$scope, &$strict) {
                $this->builtScopes[] = [$scope, $strict];
                $query = SelectBuilder::create()->from($scope)->order('id', 'DESC');
                // Fixture stock read ACL. Tenant, parent, event and attachment predicates are production code.
                $query->where(['readable' => 1]);
                if ($scope === 'Opportunity') {
                    (new Tenant($this->user, $this->tenants))->apply($query);
                }
                if ($scope === 'Note') {
                    (new NoteApplier($this->user, $this->events, $this->parents))->apply($query, SearchParams::create());
                    if ($this->portal) {
                        $query->where(['isInternal' => false]);
                    }
                }
                return $query;
            });
            return $builder;
        });
        $filters = $this->createMock(FilterFactory::class);
        $filters->method('create')->willReturnCallback(function ($scope, $user, $name) {
            self::assertSame('Opportunity', $scope);
            self::assertSame($this->user, $user);
            $this->streamFilters[] = $name;
            $where = match ($name) {
                'onlyOwn', 'portalOnlyOwn' => ['assignedUserId' => 'agent'],
                'onlyTeam' => ['teamId' => 'team-a'],
                'portalOnlyAccount' => ['accountId' => 'account-a'],
                'portalOnlyContact' => ['contactId' => 'contact-a'],
            };
            $filter = $this->createMock(Filter::class);
            $filter->method('apply')->willReturnCallback(fn ($query) => $query->where($where));
            return $filter;
        });
        $this->parents = new OpportunityAccess($this->em, $this->acl, $this->tenants, $factory, $filters);
        $this->events = new OpportunityEventAccess($factory, $this->acl);
        $this->attachments = new OpportunityAttachmentAccess($this->em, $this->acl, $this->parents, $factory);

        foreach (['a', 'b', 'c'] as $tenant) {
            $this->insert('Opportunity', ['id' => "opp-$tenant", 'tenantId' => "tenant-$tenant", 'assignedUserId' => 'agent',
                'teamId' => 'team-a', 'accountId' => 'account-a', 'contactId' => 'contact-a', 'readable' => 1]);
        }
        $this->insert('Opportunity', ['id' => 'no-read', 'tenantId' => 'tenant-a', 'readable' => 0]);
        $this->insert('Opportunity', ['id' => 'not-own', 'tenantId' => 'tenant-a', 'assignedUserId' => 'other', 'readable' => 1]);
        $this->insert('Opportunity', ['id' => 'tenantless', 'tenantId' => null, 'readable' => 1]);
        $this->insert('Opportunity', ['id' => 'deleted-parent', 'tenantId' => 'tenant-a', 'readable' => 1, 'deleted' => 1]);
        foreach (['ChatwootConversation', 'Task', 'Meeting', 'Call'] as $type) {
            $this->insert($type, ['id' => 'visible', 'readable' => 1]);
            $this->insert($type, ['id' => 'hidden', 'readable' => 0]);
        }
        // Put forbidden rows first so a post-fetch ACL would corrupt counts and underfill pages.
        foreach (['opp-b', 'opp-c', 'missing', 'no-read', 'tenantless', 'deleted-parent'] as $number => $id) {
            $this->insertNote("denied-$number", $number + 1, ['parentId' => $id]);
        }
        $this->insertNote('missing-parent-id', 7, ['parentId' => null]);
        $this->insertNote('hidden-event', 8, ['type' => OpportunityStreamEvents::MESSAGE_RECEIVED, 'relatedType' => 'ChatwootConversation', 'relatedId' => 'hidden']);
        $this->insertNote('own-post', 20);
        $this->insertNote('own-update', 21, ['type' => 'Update']);
        $this->insertNote('own-event', 22, ['type' => OpportunityStreamEvents::MESSAGE_RECEIVED, 'relatedType' => 'ChatwootConversation', 'relatedId' => 'visible']);
        $this->insertNote('unrelated', 23, ['parentType' => 'Account', 'parentId' => 'account']);
        $this->insertNote('unparented', 24, ['parentType' => null, 'parentId' => null]);
    }

    private function sqlName(string $name): string
    {
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $name));
    }

    private function insert(string $type, array $values): void
    {
        $sql = 'INSERT INTO ' . $this->sqlName($type) . ' (' . implode(', ', array_map($this->sqlName(...), array_keys($values)))
            . ') VALUES (' . implode(', ', array_fill(0, count($values), '?')) . ')';
        $this->pdo->prepare($sql)->execute(array_values($values));
    }

    private function insertNote(string $id, int $number, array $values = []): void
    {
        $this->insert('Note', $values + ['id' => $id, 'number' => $number, 'parentType' => 'Opportunity', 'parentId' => 'opp-a',
            'type' => 'Post', 'createdById' => 'agent', 'isPinned' => 1, 'readable' => 1]);
    }

    private function assertRows(array $expected, SelectBuilder $builder): void
    {
        foreach ($this->composers as $dialect => $composer) {
            $sql = $composer->composeSelect($builder->build());
            self::assertSame($expected, $this->pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN), "$dialect: $sql");
        }
    }

    public static function notePaths(): iterable
    {
        yield 'Note list' => ['list'];
        yield 'unfiltered stream' => ['stream'];
        yield 'search stream' => ['search'];
        yield 'pinned stream' => ['pinned'];
    }

    #[DataProvider('notePaths')]
    public function testNotesAreFilteredBeforeCountsAndPagination(string $path): void
    {
        if ($path === 'list') {
            $builder = SelectBuilder::create()->from('Note');
            (new NoteApplier($this->user, $this->events, $this->parents))->apply($builder, SearchParams::create());
        } else {
            $params = $path === 'search'
                ? SearchParams::fromRaw(['textFilter' => 'post', 'offset' => 50, 'maxSize' => 1]) : SearchParams::create();
            $helper = new QueryHelper($this->em, $this->selects, $this->acl, $this->user, $this->events, $this->parents);
            $builder = $helper->buildBaseQueryBuilder($params);
            if ($path === 'pinned') {
                $builder->where(['isPinned' => true]);
            }
        }
        $this->assertRows(['own-post', 'own-update', 'own-event', 'unrelated', 'unparented'], (clone $builder)->select('id')->order('number'));
        foreach ($this->composers as $composer) {
            $count = (clone $builder)->select([['COUNT:id', 'count']])->order([])->build();
            self::assertSame(5, (int) $this->pdo->query($composer->composeSelect($count))->fetchColumn());
        }
        $this->assertRows(['own-post', 'own-update'], (clone $builder)->select('id')->order('number')->limit(0, 2));
        $this->assertRows(['own-event', 'unrelated'], (clone $builder)->select('id')->order('number')->limit(2, 2));
        self::assertContains(['Opportunity', true], $this->builtScopes);
    }

    public function testMembershipRevocationAndMultipleTenantsChangeActualNoteSelection(): void
    {
        $this->tenantIds = ['tenant-a', 'tenant-b'];
        $query = fn () => SelectBuilder::create()->from('Note')->select('id')->order('number')->where($this->parents->where($this->user));
        $this->assertRows(['denied-0', 'hidden-event', 'own-post', 'own-update', 'own-event', 'unrelated', 'unparented'], $query());
        $this->tenantIds = [];
        $this->assertRows(['unrelated', 'unparented'], $query());
        $this->admin = true;
        self::assertSame([], $this->parents->where($this->user));
    }

    public static function scopeDenials(): iterable
    {
        yield 'no read' => [['Opportunity:read'], 'all', ['tenant-a']];
        yield 'no stream' => [['Opportunity:stream'], 'all', ['tenant-a']];
        yield 'no membership' => [[], 'all', []];
        yield 'no stream level' => [[], 'no', ['tenant-a']];
        yield 'unknown stream level' => [[], 'unexpected', ['tenant-a']];
    }

    #[DataProvider('scopeDenials')]
    public function testListAccessFailsClosedWithoutReadStreamOrMembership(array $denied, string $level, array $tenants): void
    {
        $this->deniedScopes = $denied;
        $this->streamLevel = $level;
        $this->tenantIds = $tenants;
        self::assertNull($this->parents->readableOpportunities($this->user));
        $this->assertRows(['unrelated', 'unparented'], SelectBuilder::create()->from('Note')->select('id')->order('number')->where($this->parents->where($this->user)));
        if ($denied || !$tenants) {
            self::assertSame([], $this->builtScopes, 'An absent scope or membership must not issue a parent ACL query.');
        }
    }

    public static function streamLevels(): iterable
    {
        yield 'own' => [false, 'own', 'onlyOwn'];
        yield 'team' => [false, 'team', 'onlyTeam'];
        yield 'portal own' => [true, 'own', 'portalOnlyOwn'];
        yield 'portal account' => [true, 'account', 'portalOnlyAccount'];
        yield 'portal contact' => [true, 'contact', 'portalOnlyContact'];
        yield 'all' => [false, 'all', null];
        yield 'yes' => [false, 'yes', null];
    }

    #[DataProvider('streamLevels')]
    public function testStreamScopeCanOnlyNarrowStrictReadAndTenantSelection(bool $portal, string $level, ?string $filter): void
    {
        $this->portal = $portal;
        $this->streamLevel = $level;
        $parents = $this->parents->readableOpportunities($this->user);
        self::assertNotNull($parents);
        self::assertSame($filter ? [$filter] : [], $this->streamFilters);
        self::assertSame([['Opportunity', true]], $this->builtScopes);
        $query = SelectBuilder::create()->clone($parents)->order('id');
        $this->assertRows($filter ? ['opp-a'] : ['not-own', 'opp-a'], $query);
    }

    public function testMandatoryTenantFilterCannotBeReplacedByRequestedForeignTenant(): void
    {
        foreach ([false, true] as $portal) {
            $this->portal = $portal;
            $builder = SelectBuilder::create()->from('Opportunity')->select('id')->where(['tenantId' => 'tenant-c']);
            (new Tenant($this->user, $this->tenants))->apply($builder);
            $this->assertRows([], $builder);
            $this->tenantIds = [];
            $builder = SelectBuilder::create()->from('Opportunity')->select('id');
            (new Tenant($this->user, $this->tenants))->apply($builder);
            $this->assertRows([], $builder);
            $this->tenantIds = ['tenant-a'];
        }
        $this->admin = true;
        $builder = SelectBuilder::create()->from('Opportunity')->select('id')->where(['tenantId' => 'tenant-c']);
        (new Tenant($this->user, $this->tenants))->apply($builder);
        $this->assertRows(['opp-c'], $builder);
    }

    public function testAttachmentListsHonorParentOverRelatedAndFilterBeforePagination(): void
    {
        $links = [
            '01-foreign-parent' => ['Note', 'denied-0', 'Note', 'own-post'],
            '02-missing-parent' => ['Note', 'missing', 'Note', 'own-post'],
            '03-related-foreign' => [null, null, 'Note', 'denied-0'],
            '04-missing-parent-type' => [null, 'own-post', 'Note', 'denied-0'],
            '05-missing-parent-id' => ['Note', null, 'Note', 'denied-0'],
            '06-hidden-event' => ['Note', 'hidden-event', null, null],
            '07-foreign-opportunity' => ['Opportunity', 'opp-b', null, null],
            '08-related-opportunity' => [null, null, 'Opportunity', 'opp-b'],
            '09-tenantless-note' => ['Note', 'denied-4', null, null],
            '10-own-parent' => ['Note', 'own-post', 'Note', 'denied-0'],
            '11-unrelated-parent' => ['Account', 'account', 'Note', 'denied-0'],
            '12-unlinked' => [null, null, null, null],
            '13-own-related' => [null, null, 'Note', 'own-post'],
            '14-own-opportunity' => ['Opportunity', 'opp-a', 'Note', 'denied-0'],
            '15-incomplete-upload' => ['Note', null, null, null],
        ];
        foreach ($links as $id => [$parentType, $parentId, $relatedType, $relatedId]) {
            $this->insert('Attachment', compact('id', 'parentType', 'parentId', 'relatedType', 'relatedId') + ['createdById' => 'agent']);
        }
        $builder = SelectBuilder::create()->from('Attachment');
        (new AttachmentApplier($this->user, $this->attachments))->apply($builder, SearchParams::create());
        $expected = ['10-own-parent', '11-unrelated-parent', '12-unlinked', '13-own-related', '14-own-opportunity', '15-incomplete-upload'];
        $this->assertRows($expected, (clone $builder)->select('id')->order('id'));
        $this->assertRows(array_slice($expected, 0, 2), (clone $builder)->select('id')->order('id')->limit(0, 2));
        foreach ($this->composers as $composer) {
            self::assertSame(6, (int) $this->pdo->query($composer->composeSelect((clone $builder)->select([['COUNT:id', 'count']])->build()))->fetchColumn());
        }
        self::assertContains(['Note', true], $this->builtScopes);
        self::assertContains(['Opportunity', true], $this->builtScopes);
    }

    public function testAttachmentScopeDenialsAndRevocationPreserveOnlyUnlinkedOrUnrelatedUploads(): void
    {
        foreach ([['parentType' => 'Note', 'parentId' => 'own-post'], ['relatedType' => 'Opportunity', 'relatedId' => 'opp-a'], []] as $i => $values) {
            $this->insert('Attachment', ['id' => "file-$i"] + $values);
        }
        $this->deniedScopes = ['Note:read', 'Opportunity:read'];
        $query = fn () => SelectBuilder::create()->from('Attachment')->select('id')->order('id')->where($this->attachments->where($this->user));
        $this->assertRows(['file-2'], $query());
        self::assertSame([], $this->builtScopes);
        $this->deniedScopes = [];
        $this->tenantIds = [];
        $this->assertRows(['file-2'], $query());
        $this->admin = true;
        $this->assertRows(['file-0', 'file-1', 'file-2'], $query());
    }

    public function testPortalListsDenyForeignNotesEventsAndTheirAttachments(): void
    {
        $this->portal = true;
        $this->insertNote('internal', 25, ['isInternal' => 1]);
        foreach (['denied-0', 'own-event', 'internal', 'own-post'] as $id) {
            $this->insert('Attachment', ['id' => $id, 'parentType' => 'Note', 'parentId' => $id, 'createdById' => 'agent']);
        }
        $query = SelectBuilder::create()->from('Attachment')->select('id')->where($this->attachments->where($this->user));
        $this->assertRows(['own-post'], $query);
    }

    public static function emptyParentLinks(): iterable
    {
        yield 'empty parent id' => ['Account', ''];
        yield 'empty parent type' => ['', 'account'];
        yield 'falsey parent id' => ['Account', '0'];
        yield 'falsey parent type' => ['0', 'account'];
    }

    #[DataProvider('emptyParentLinks')]
    public function testEmptyParentLinkCannotBypassDeniedRelatedNoteInAttachmentLists(string $parentType, string $parentId): void
    {
        $this->insert('Attachment', ['id' => 'foreign', 'parentType' => $parentType, 'parentId' => $parentId,
            'relatedType' => 'Note', 'relatedId' => 'denied-0', 'createdById' => 'agent']);
        $builder = SelectBuilder::create()->from('Attachment')->select('id')->where($this->attachments->where($this->user));
        $rows = [];
        foreach ($this->composers as $dialect => $composer) {
            $rows[$dialect] = $this->pdo->query($composer->composeSelect($builder->build()))->fetchAll(PDO::FETCH_COLUMN);
        }
        self::assertSame(['mysql' => [], 'postgresql' => []], $rows);
    }
}

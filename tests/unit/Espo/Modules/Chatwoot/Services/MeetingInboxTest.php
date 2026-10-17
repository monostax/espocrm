<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\Chatwoot\Services;

use Espo\Core\Acl;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Record\Collection;
use Espo\Core\Record\Service;
use Espo\Core\Record\ServiceContainer;
use Espo\Core\Select\SelectBuilderFactory;
use Espo\Core\Utils\Metadata as AppMetadata;
use Espo\Entities\User;
use Espo\Modules\Chatwoot\Services\ActivityDiscussion;
use Espo\Modules\Chatwoot\Services\ActivityInbox;
use Espo\Modules\Chatwoot\Tools\Activities\Access;
use Espo\Modules\Global\Tools\CrmTags;
use Espo\ORM\BaseEntity;
use Espo\ORM\EntityCollection;
use Espo\ORM\EntityFactory;
use Espo\ORM\EntityManager;
use Espo\ORM\Executor\QueryExecutor;
use Espo\ORM\Metadata;
use Espo\ORM\MetadataDataProvider;
use Espo\ORM\Query\SelectBuilder;
use Espo\ORM\Query\Union;
use Espo\ORM\QueryComposer\MysqlQueryComposer;
use Espo\ORM\Repository\RDBRepository;
use Espo\ORM\Repository\RDBSelectBuilder;
use PDO;
use PHPUnit\Framework\TestCase;

/** Execute production list/count queries over interleaved, multi-page fixtures. */
final class MeetingInboxTest extends TestCase
{
    private PDO $pdo;
    private MysqlQueryComposer $composer;
    private ActivityInbox $inbox;
    private BaseEntity $tenant;
    private array $defs = [];
    private bool $meetingReadable = true;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $this->pdo->sqliteCreateFunction('IF', fn ($condition, $yes, $no) => $condition ? $yes : $no);
        foreach (Access::TYPES as $type) {
            $fields = ['id', 'name', 'tenantId', 'status', 'assignedUserId', 'dateEnd', 'dateEndDate', 'createdAt', 'modifiedAt', 'readable', 'unread', 'deleted'];
            $columns = [];
            foreach ($fields as $field) {
                $numeric = in_array($field, ['readable', 'unread', 'deleted'], true);
                $this->defs[$type]['attributes'][$field] = ['type' => $numeric ? 'int' : 'varchar'];
                $columns[] = $this->snake($field) . ($numeric ? ' INTEGER DEFAULT 0' : ' TEXT');
            }
            $this->pdo->exec('CREATE TABLE ' . strtolower($type) . ' (' . implode(', ', $columns) . ')');
            for ($i = 0; $i < 107; $i++) {
                $this->pdo->prepare('INSERT INTO ' . strtolower($type) . ' (id,name,tenant_id,status,assigned_user_id,date_end,readable,unread) VALUES (?,?,?,?,?,?,?,?)')->execute([
                    sprintf('%03d', $i), sprintf('Item %03d', $i), $i === 106 ? 'tenant-b' : 'tenant-a',
                    $i % 2 ? ($type === 'Task' ? 'Completed' : 'Held') : 'Planned',
                    $i % 2 ? 'other' : 'viewer', '2030-01-01 10:00:00', $i === 105 ? 0 : 1, $i < 3 ? 1 : 0,
                ]);
            }
        }
        $provider = $this->createMock(MetadataDataProvider::class);
        $provider->method('get')->willReturn($this->defs);
        $entities = $this->createMock(EntityFactory::class);
        $entities->method('create')->willReturnCallback(fn ($type) => new BaseEntity($type, $this->defs[$type]));
        $this->composer = new MysqlQueryComposer($this->pdo, $entities, new Metadata($provider));
        $em = $this->createMock(EntityManager::class);
        $executor = $this->createMock(QueryExecutor::class);
        $executor->method('execute')->willReturnCallback(function ($query) {
            $sql = $this->composer->compose($query);
            // SQLite needs a derived SELECT around each parenthesized UNION member.
            if ($query instanceof Union) $sql = preg_replace('/(^|UNION ALL )\(/', '$1SELECT * FROM (', $sql);
            return $this->pdo->query($sql);
        });
        $em->method('getQueryExecutor')->willReturn($executor);
        $em->method('getRDBRepository')->willReturnCallback(function ($type) {
            $repo = $this->createMock(RDBRepository::class);
            if ($type === 'Note') {
                $empty = $this->createMock(RDBSelectBuilder::class);
                $empty->method('where')->willReturnSelf();
                $empty->method('find')->willReturn(new EntityCollection([]));
                $repo->method('select')->willReturn($empty);
            }
            $repo->method('clone')->willReturnCallback(fn ($query) => $this->repository($type, SelectBuilder::create()->clone($query)));
            return $repo;
        });
        $acl = $this->createMock(Acl::class);
        $acl->method('checkScope')->willReturnCallback(fn ($type) => in_array($type, Access::TYPES, true) && ($type !== 'Meeting' || $this->meetingReadable));
        $acl->method('getScopeForbiddenFieldList')->willReturnCallback(fn ($type, $action = 'read') => $action === 'edit' ? ['name'] : ['description']);
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn('viewer');
        $access = $this->createMock(Access::class);
        $access->method('type')->willReturnArgument(0);
        $access->method('query')->willReturnCallback(fn ($type, $tenant, $stream = false) => SelectBuilder::create()->from($type)->where([
            'tenantId' => $tenant->getId(), 'readable' => 1,
        ]));
        $discussion = $this->createMock(ActivityDiscussion::class);
        $discussion->method('states')->willReturn([]);
        $discussion->method('applyFilter')->willReturnCallback(function ($query) { $query->where(['unread' => 1]); });
        $services = $this->createMock(ServiceContainer::class);
        $services->method('get')->willReturnCallback(function ($type) {
            $service = $this->createMock(Service::class);
            $service->method('find')->willReturnCallback(fn ($params) => new Collection($this->repository($type,
                SelectBuilder::create()->from($type)->where(['id' => $params->getRaw()['where'][0]['value']])
            )->find()));
            return $service;
        });
        $metadata = $this->createMock(AppMetadata::class);
        $metadata->method('get')->willReturnCallback(fn ($path) => $path[0] === 'entityDefs' ? [
            'name' => ['type' => 'varchar'], 'description' => ['type' => 'text'], 'status' => ['type' => 'enum'],
        ] : null);
        $tags = $this->createMock(CrmTags::class);
        $tags->method('counts')->willReturn([]);
        $tags->method('decorate')->willReturnArgument(1);
        $this->inbox = new ActivityInbox($em, $acl, $user, $metadata, $access, $discussion, $services, $this->createMock(SelectBuilderFactory::class), $tags);
        $this->tenant = new BaseEntity('Tenant', ['attributes' => ['id' => ['type' => 'varchar']]]);
        $this->tenant->set('id', 'tenant-a');
    }

    private function snake(string $value): string
    {
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $value));
    }

    private function repository(string $type, SelectBuilder $query): RDBSelectBuilder
    {
        $builder = $this->createMock(RDBSelectBuilder::class);
        $builder->method('count')->willReturnCallback(fn () => (int) $this->pdo->query($this->composer->composeSelect(
            (clone $query)->select([['COUNT:id', 'count']])->build()
        ))->fetchColumn());
        $builder->method('find')->willReturnCallback(function () use ($query, $type) {
            $rows = $this->pdo->query($this->composer->composeSelect($query->build()))->fetchAll(PDO::FETCH_ASSOC);
            return new EntityCollection(array_map(function ($row) use ($type) {
                $entity = new BaseEntity($type, $this->defs[$type]);
                $entity->set($row);
                return $entity;
            }, $rows));
        });
        return $builder;
    }

    public function testBoundariesPrecedePaginationTotalsAndAclHydration(): void
    {
        foreach ([['Meeting'], ['Task', 'Call']] as $allowed) {
            $keys = [];
            for ($offset = 0; $offset < 105 * count($allowed); $offset += 100) {
                $page = $this->inbox->list($this->tenant, ['offset' => $offset, 'maxSize' => 999, 'sort' => 'name'], $allowed);
                self::assertSame(105 * count($allowed), $page->total);
                self::assertLessThanOrEqual(100, count($page->list));
                self::assertSame($offset + 100 < $page->total, $page->hasMore);
                foreach ($page->list as $row) {
                    self::assertContains($row->entityType, $allowed);
                    self::assertNotContains($row->id, ['105', '106']);
                    $keys[] = $row->entityType . ':' . $row->id;
                }
            }
            self::assertCount(105 * count($allowed), array_unique($keys));
        }
    }

    public function testCountsAreBoundedSidebarDestinationsAndRailIgnoresSelectedLeaf(): void
    {
        $meetings = $this->inbox->counts($this->tenant, ['status' => 'Finished', 'type' => 'Meeting', 'search' => 'missing'], ['Meeting']);
        self::assertSame(105, $meetings->all);
        self::assertSame(['Meeting' => 105], $meetings->types);
        self::assertSame(['Open' => 53, 'Finished' => 52], $meetings->statusGroups);
        self::assertSame(3, $meetings->unread);
        self::assertSame(210, $this->inbox->counts($this->tenant, [])->all);
        self::assertSame(3, $this->inbox->counts($this->tenant, ['assignee_tab' => 'unassigned', 'railOnly' => 'true'], ['Meeting'])->unread);
        self::assertSame(53, $this->inbox->counts($this->tenant, ['assignee_tab' => 'me'], ['Meeting'])->all);
    }

    public function testMetadataKeepsTypeScopeAndFieldPermissions(): void
    {
        self::assertSame(['Task', 'Call'], array_keys((array) $this->inbox->metadata()));
        $metadata = $this->inbox->metadata(['Meeting']);
        self::assertSame(['Meeting'], array_keys((array) $metadata));
        self::assertArrayNotHasKey('description', $metadata->Meeting['fields']);
        self::assertTrue($metadata->Meeting['fields']['name']['readOnly']);
        $this->meetingReadable = false;
        self::assertSame([], (array) $this->inbox->metadata(['Meeting']));
        self::assertSame(0, $this->inbox->list($this->tenant, [], ['Meeting'])->total);
    }

    public function testIncompatibleFiltersAreRejectedAcrossReadEndpoints(): void
    {
        foreach ([['Meeting', ['Task', 'Call']], ['Task', ['Meeting']], ['Call', ['Meeting']]] as [$type, $allowed]) {
            foreach (['list', 'counts', 'metadata'] as $method) {
                try {
                    if ($method === 'metadata') $this->inbox->metadata($allowed, ['type' => $type]);
                    else $this->inbox->$method($this->tenant, ['type' => $type], $allowed);
                    self::fail('An incompatible inbox type was accepted.');
                } catch (BadRequest) {
                    self::assertTrue(true);
                }
            }
        }
    }
}

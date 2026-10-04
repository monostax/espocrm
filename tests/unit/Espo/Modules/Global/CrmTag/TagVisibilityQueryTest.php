<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\Global\CrmTag;

use Espo\Core\Acl;
use Espo\Core\Select\SelectBuilder as CoreSelectBuilder;
use Espo\Core\Select\SelectBuilderFactory;
use Espo\Core\Select\Where\Item;
use Espo\Entities\User;
use Espo\Modules\Global\Classes\Select\CrmTag\AccessControlFilters\Teams;
use Espo\Modules\Global\Classes\Select\CrmTag\VisibleTagFilter;
use Espo\Modules\Global\Tools\CrmTags;
use Espo\Modules\Global\Tools\Tenant\TenantResolver;
use Espo\Modules\Global\Tools\Tenant\UserTenantResolver;
use Espo\ORM\BaseEntity;
use Espo\ORM\EntityFactory;
use Espo\ORM\EntityManager;
use Espo\ORM\Executor\QueryExecutor;
use Espo\ORM\Metadata;
use Espo\ORM\MetadataDataProvider;
use Espo\ORM\Query\SelectBuilder;
use Espo\ORM\QueryComposer\MysqlQueryComposer;
use Espo\ORM\QueryComposer\PostgresqlQueryComposer;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TagVisibilityQueryTest extends TestCase
{
    public static function dialects(): array
    {
        return [[MysqlQueryComposer::class], [PostgresqlQueryComposer::class]];
    }

    #[DataProvider('dialects')]
    public function testFiltersBadgesCountsAndResponseUseOnlyVisibleTags(string $dialect): void
    {
        $pdo = new PDO('sqlite::memory:', options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE crm_tag (id TEXT, name TEXT, visibility TEXT, owner_user_id TEXT, tenant_id TEXT, deleted INT DEFAULT 0)');
        $pdo->exec('CREATE TABLE entity_team (entity_id TEXT, entity_type TEXT, team_id TEXT, deleted INT DEFAULT 0)');
        $pdo->exec('CREATE TABLE opportunity (id TEXT, deleted INT DEFAULT 0)');
        $pdo->exec('CREATE TABLE crm_tag_opportunity (opportunity_id TEXT, crm_tag_id TEXT, deleted INT DEFAULT 0)');
        $pdo->exec("INSERT INTO crm_tag (id, name, visibility, owner_user_id, tenant_id) VALUES
            ('mine', 'My tag', 'personal', 'alice', 'workspace'),
            ('hidden', 'Private name', 'personal', 'bob', 'workspace'),
            ('shared', 'Shared', 'team', NULL, 'workspace')");
        $pdo->exec("INSERT INTO entity_team (entity_id, entity_type, team_id) VALUES ('shared', 'CrmTag', 'team')");
        $pdo->exec("INSERT INTO opportunity (id) VALUES ('mixed'), ('private-only'), ('empty')");
        $pdo->exec("INSERT INTO crm_tag_opportunity (opportunity_id, crm_tag_id) VALUES
            ('mixed', 'mine'), ('mixed', 'hidden'), ('mixed', 'shared'), ('private-only', 'hidden')");

        $defs = [
            'CrmTag' => ['attributes' => array_fill_keys(['id', 'name', 'visibility', 'ownerUserId', 'tenantId', 'deleted'], ['type' => 'varchar'])],
            'EntityTeam' => ['attributes' => array_fill_keys(['entityId', 'entityType', 'teamId', 'deleted'], ['type' => 'varchar'])],
            'Opportunity' => [
                'attributes' => ['id' => ['type' => 'varchar'], 'deleted' => ['type' => 'bool'],
                    'tagsIds' => ['type' => 'jsonArray', 'notStorable' => true],
                    'tagsNames' => ['type' => 'jsonObject', 'notStorable' => true]],
                'relations' => ['tags' => ['type' => 'manyMany', 'entity' => 'CrmTag',
                    'relationName' => 'crmTagOpportunity', 'midKeys' => ['opportunityId', 'crmTagId']]],
            ],
        ];
        $provider = $this->createMock(MetadataDataProvider::class);
        $provider->method('get')->willReturn($defs);
        $entities = $this->createMock(EntityFactory::class);
        $entities->method('create')->willReturnCallback(fn ($type) => new BaseEntity($type, $defs[$type]));
        $composer = new $dialect($pdo, $entities, new Metadata($provider));
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn('alice');
        $user->method('getTeamIdList')->willReturn(['team']);
        $tenants = $this->createMock(UserTenantResolver::class);
        $tenants->method('resolveTenantIds')->willReturn(['workspace']);
        $builder = $this->createMock(CoreSelectBuilder::class);
        $builder->method('from')->willReturnSelf();
        $builder->method('withStrictAccessControl')->willReturnSelf();
        $builder->method('buildQueryBuilder')->willReturnCallback(function () use ($user, $tenants) {
            $query = SelectBuilder::create()->from('CrmTag');
            (new Teams($user, $tenants))->apply($query);
            return $query;
        });
        $factory = $this->createMock(SelectBuilderFactory::class);
        $factory->method('create')->willReturn($builder);
        $executor = $this->createMock(QueryExecutor::class);
        $executor->method('execute')->willReturnCallback(fn ($query) => $pdo->query($composer->compose($query)));
        $em = $this->createMock(EntityManager::class);
        $em->method('getQueryExecutor')->willReturn($executor);
        $acl = $this->createMock(Acl::class);
        $acl->method('checkScope')->willReturn(true);
        $acl->method('checkField')->willReturn(true);
        $tags = new CrmTags($em, $acl, $factory, $this->createMock(TenantResolver::class), $user, $tenants);
        $converter = new VisibleTagFilter('Opportunity', $tags);

        foreach ([
            ['linkedWith', ['hidden'], []],
            ['linkedWith', ['mine'], ['mixed']],
            ['linkedWithAll', ['mine', 'shared'], ['mixed']],
            ['linkedWithAll', ['mine', 'hidden'], []],
            ['notLinkedWith', ['hidden'], ['empty', 'mixed', 'private-only']],
            ['isLinked', null, ['mixed']],
            ['isNotLinked', null, ['empty', 'private-only']],
        ] as [$type, $value, $expected]) {
            $query = SelectBuilder::create()->from('Opportunity')->select(['id'])->order('id');
            $query->where($converter->convert($query, Item::fromRaw([
                'type' => $type, 'attribute' => 'tags', 'value' => $value,
            ])));
            self::assertSame($expected, $pdo->query($composer->compose($query->build()))->fetchAll(PDO::FETCH_COLUMN), $type);
        }
        $record = new BaseEntity('Opportunity', $defs['Opportunity']);
        $record->set(['id' => 'mixed', 'tagsIds' => ['mine', 'hidden', 'shared'],
            'tagsNames' => (object) ['mine' => 'My tag', 'hidden' => 'Private name', 'shared' => 'Shared']]);
        $tags->filterOutput($record);
        self::assertSame(['mine', 'shared'], $record->get('tagsIds'));
        self::assertEquals((object) ['mine' => 'My tag', 'shared' => 'Shared'], $record->get('tagsNames'));
        $rows = $tags->decorate('Opportunity', [(object) ['id' => 'private-only']]);
        self::assertSame([], $rows[0]->tagsIds);
        self::assertEquals((object) [], $rows[0]->tagsNames);
        self::assertEquals(['mine' => 1, 'shared' => 1], $tags->counts('Opportunity', SelectBuilder::create()->from('Opportunity')));
    }
}

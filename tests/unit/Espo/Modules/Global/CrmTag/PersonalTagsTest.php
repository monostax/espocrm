<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\Global\CrmTag;

use Espo\Core\Acl;
use Espo\Core\Acl\DefaultAccessChecker;
use Espo\Core\Acl\ScopeData;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Select\SelectBuilderFactory;
use Espo\Entities\User;
use Espo\Modules\Global\Classes\Acl\CrmTag\AccessChecker;
use Espo\Modules\Global\Classes\Select\CrmTag\AccessControlFilters\Teams;
use Espo\Modules\Global\Hooks\CrmTag\ValidateWorkspace;
use Espo\Modules\Global\Services\TeamTenantAccess;
use Espo\Modules\Global\Tools\Acl\TeamsAccess;
use Espo\Modules\Global\Tools\CrmTags;
use Espo\Modules\Global\Tools\Tenant\TenantResolver;
use Espo\Modules\Global\Tools\Tenant\UserTenantResolver;
use Espo\ORM\BaseEntity;
use Espo\ORM\EntityCollection;
use Espo\ORM\EntityFactory;
use Espo\ORM\EntityManager;
use Espo\ORM\Metadata;
use Espo\ORM\MetadataDataProvider;
use Espo\ORM\Query\SelectBuilder;
use Espo\ORM\QueryComposer\MysqlQueryComposer;
use Espo\ORM\QueryComposer\PostgresqlQueryComposer;
use Espo\ORM\Repository\RDBRelation;
use Espo\ORM\Repository\RDBRepository;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PersonalTagsTest extends TestCase
{
    private function entity(array $values, bool $existing = false, string $type = 'CrmTag'): BaseEntity
    {
        $attributes = array_fill_keys(['id', 'name', 'visibility', 'tenantId', 'ownerUserId', 'nameScope'], ['type' => 'varchar']);
        foreach (['teamsIds', 'tagsIds'] as $name) $attributes[$name] = ['type' => 'jsonArray'];
        foreach (['teamsNames', 'tagsNames', 'tagsColumns'] as $name) $attributes[$name] = ['type' => 'jsonObject'];
        $entity = new BaseEntity($type, ['attributes' => $attributes]);
        $entity->set($values);
        if ($existing) {
            $entity->setAsNotNew();
            $entity->updateFetchedValues();
        }
        return $entity;
    }

    private function user(string $id = 'alice', bool $admin = false): User
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn($id);
        $user->method('isAdmin')->willReturn($admin);
        $user->method('getTeamIdList')->willReturn(['shared-team']);
        return $user;
    }

    private function tenants(array $ids = ['workspace']): UserTenantResolver
    {
        $tenants = $this->createMock(UserTenantResolver::class);
        $tenants->method('resolveTenantIds')->willReturn($ids);
        $tenants->method('canActForTenant')->willReturnCallback(fn ($user, $id) => in_array($id, $ids, true));
        return $tenants;
    }

    private function validator(array $ids = ['workspace']): ValidateWorkspace
    {
        return new ValidateWorkspace($this->createMock(TeamTenantAccess::class), $this->user(), $this->tenants($ids));
    }

    public function testPersonalCreationAssignsOwnerAndSoleWorkspaceAndClearsTeams(): void
    {
        $tag = $this->entity(['visibility' => 'personal', 'ownerUserId' => 'bob', 'teamsIds' => ['shared-team']]);
        $this->validator()->process($tag);
        self::assertSame('alice', $tag->get('ownerUserId'));
        self::assertSame('workspace', $tag->get('tenantId'));
        self::assertSame('user:alice', $tag->get('nameScope'));
        self::assertSame([], $tag->get('teamsIds'));
    }

    public function testAmbiguousWorkspaceIsNotGuessed(): void
    {
        $this->expectException(BadRequest::class);
        $this->validator(['workspace', 'other'])->process($this->entity(['visibility' => 'personal']));
    }

    public function testExplicitWorkspaceWorksForMultiWorkspaceUser(): void
    {
        $tag = $this->entity(['visibility' => 'personal', 'tenantId' => 'other']);
        $this->validator(['workspace', 'other'])->process($tag);
        self::assertSame('other', $tag->get('tenantId'));
    }

    public function testForeignWorkspaceIsRejected(): void
    {
        $this->expectException(BadRequest::class);
        $this->validator()->process($this->entity(['visibility' => 'personal', 'tenantId' => 'other']));
    }

    public static function immutableFields(): array
    {
        return [['visibility', 'team'], ['ownerUserId', 'bob'], ['tenantId', 'other']];
    }

    #[DataProvider('immutableFields')]
    public function testExistingOwnershipAndScopeCannotChange(string $field, string $value): void
    {
        $tag = $this->entity(['visibility' => 'personal', 'tenantId' => 'workspace', 'ownerUserId' => 'alice'], true);
        $tag->set($field, $value);
        $this->expectException(BadRequest::class);
        $this->validator()->process($tag);
    }

    public function testTeamTagCannotBeSavedWithoutTeams(): void
    {
        $this->expectException(BadRequest::class);
        $this->validator()->process($this->entity(['visibility' => 'team', 'tenantId' => 'workspace', 'teamsIds' => []]));
    }

    public static function actors(): array
    {
        return [['alice', false, true], ['bob', false, false], ['admin', true, false], ['alice', true, true]];
    }

    #[DataProvider('actors')]
    public function testOnlyOwnerCanReadEditDeleteAndStreamPersonalTags(string $id, bool $admin, bool $allowed): void
    {
        $defaults = $this->createMock(DefaultAccessChecker::class);
        foreach (['checkRead', 'checkEdit', 'checkDelete'] as $method) $defaults->method($method)->willReturn(true);
        $teams = $this->createMock(TeamsAccess::class);
        $teams->method('userSharesTeam')->willReturn(true);
        $checker = new AccessChecker($defaults, $teams, $this->tenants());
        $tag = $this->entity(['visibility' => 'personal', 'tenantId' => 'workspace', 'ownerUserId' => 'alice']);
        foreach (['Read', 'Edit', 'Delete', 'Stream'] as $action) {
            self::assertSame($allowed, $checker->{'checkEntity' . $action}($this->user($id, $admin), $tag, ScopeData::fromRaw(true)));
        }
    }

    public function testOwnerLosingWorkspaceMembershipLosesAccess(): void
    {
        $defaults = $this->createMock(DefaultAccessChecker::class);
        $checker = new AccessChecker($defaults, $this->createMock(TeamsAccess::class), $this->tenants([]));
        self::assertFalse($checker->checkEntityRead($this->user(), $this->entity([
            'visibility' => 'personal', 'tenantId' => 'workspace', 'ownerUserId' => 'alice',
        ]), ScopeData::fromRaw(true)));
    }

    public function testListQueriesExcludeOtherOwnersEvenForAdminsInBothSqlDialects(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE crm_tag (id TEXT, visibility TEXT, owner_user_id TEXT, tenant_id TEXT, deleted INT DEFAULT 0)');
        $pdo->exec('CREATE TABLE entity_team (entity_id TEXT, entity_type TEXT, team_id TEXT, deleted INT DEFAULT 0)');
        $pdo->exec("INSERT INTO crm_tag (id, visibility, owner_user_id, tenant_id) VALUES
            ('mine', 'personal', 'alice', 'workspace'), ('theirs', 'personal', 'bob', 'workspace'),
            ('foreign', 'personal', 'alice', 'other'), ('shared', 'team', NULL, 'workspace'),
            ('legacy', NULL, NULL, 'workspace'), ('unshared', 'team', NULL, 'workspace')");
        $pdo->exec("INSERT INTO entity_team (entity_id, entity_type, team_id) VALUES
            ('shared', 'CrmTag', 'shared-team'), ('legacy', 'CrmTag', 'shared-team'),
            ('theirs', 'CrmTag', 'shared-team')");
        $defs = [
            'CrmTag' => ['attributes' => array_fill_keys(['id', 'visibility', 'ownerUserId', 'tenantId', 'deleted'], ['type' => 'varchar'])],
            'EntityTeam' => ['attributes' => array_fill_keys(['entityId', 'entityType', 'teamId', 'deleted'], ['type' => 'varchar'])],
        ];
        $provider = $this->createMock(MetadataDataProvider::class);
        $provider->method('get')->willReturn($defs);
        $entities = $this->createMock(EntityFactory::class);
        $entities->method('create')->willReturnCallback(fn ($type) => new BaseEntity($type, $defs[$type]));
        foreach ([MysqlQueryComposer::class, PostgresqlQueryComposer::class] as $class) {
            $composer = new $class($pdo, $entities, new Metadata($provider));
            foreach ([false, true] as $admin) {
                $query = SelectBuilder::create()->from('CrmTag')->select(['id'])->order('id');
                (new Teams($this->user('alice', $admin), $this->tenants()))->apply($query);
                $actual = $pdo->query($composer->compose($query->build()))->fetchAll(PDO::FETCH_COLUMN);
                self::assertSame($admin ? ['legacy', 'mine', 'shared', 'unshared'] : ['legacy', 'mine', 'shared'], $actual);
            }
        }
    }

    public function testReplacingVisibleTagsPreservesOtherUsersHiddenAssociations(): void
    {
        $hidden = $this->entity(['id' => 'hidden', 'visibility' => 'personal', 'ownerUserId' => 'bob', 'tenantId' => 'workspace']);
        $visible = $this->entity(['id' => 'visible', 'visibility' => 'team', 'tenantId' => 'workspace']);
        $relation = $this->createMock(RDBRelation::class);
        $relation->method('find')->willReturn(new EntityCollection([$hidden, $visible]));
        $repository = $this->createMock(RDBRepository::class);
        $repository->method('getRelation')->willReturn($relation);
        $em = $this->createMock(EntityManager::class);
        $em->method('getRDBRepository')->willReturn($repository);
        $acl = $this->createMock(Acl::class);
        $acl->method('checkEntityRead')->willReturnCallback(fn ($tag) => $tag->getId() !== 'hidden');
        $tags = new CrmTags($em, $acl, $this->createMock(SelectBuilderFactory::class),
            $this->createMock(TenantResolver::class), $this->user(), $this->tenants());
        $record = $this->entity(['id' => 'record', 'tenantId' => 'workspace', 'tagsIds' => []], true, 'Opportunity');
        $tags->preserveHidden($record);
        self::assertSame(['hidden'], $record->get('tagsIds'));
    }

    public function testOutputStripsAllTagDataWhenFieldAccessIsDenied(): void
    {
        $tags = new CrmTags($this->createMock(EntityManager::class), $this->createMock(Acl::class),
            $this->createMock(SelectBuilderFactory::class), $this->createMock(TenantResolver::class),
            $this->user(), $this->tenants());
        $record = $this->entity(['id' => 'record', 'tagsIds' => ['hidden'],
            'tagsNames' => (object) ['hidden' => 'Private'], 'tagsColumns' => (object) ['hidden' => ['color' => 'red']]], true, 'Task');
        $tags->filterOutput($record);
        self::assertSame([], $record->get('tagsIds'));
        self::assertEquals((object) [], $record->get('tagsNames'));
        self::assertEquals((object) [], $record->get('tagsColumns'));
    }
}

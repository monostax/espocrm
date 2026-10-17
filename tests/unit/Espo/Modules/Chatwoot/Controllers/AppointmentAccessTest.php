<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\Chatwoot\Controllers;

use Espo\Core\Acl;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Select\AccessControl\FilterFactory;
use Espo\Core\Select\SelectBuilder as AclBuilder;
use Espo\Core\Select\SelectBuilderFactory;
use Espo\Entities\User;
use Espo\Modules\Chatwoot\Tools\Activities\Access;
use Espo\Modules\Global\Hooks\Appointment\SyncAssignedUsers;
use Espo\Modules\Global\Tools\Tenant\UserTenantResolver;
use Espo\ORM\BaseEntity;
use Espo\ORM\Defs;
use Espo\ORM\Defs\EntityDefs;
use Espo\ORM\EntityCollection;
use Espo\ORM\EntityManager;
use Espo\ORM\Query\SelectBuilder;
use Espo\ORM\Repository\Option\SaveOptions;
use Espo\ORM\Repository\RDBRepository;
use Espo\ORM\Repository\RDBSelectBuilder;
use PHPUnit\Framework\TestCase;

final class AppointmentAccessTest extends TestCase
{
    private Access $access;
    private BaseEntity $tenant;
    private ?string $deniedField = null;

    protected function setUp(): void
    {
        $this->tenant = new BaseEntity('Tenant', ['attributes' => ['id' => ['type' => 'varchar']]]);
        $this->tenant->set('id', 'tenant-a');
        $em = $this->createMock(EntityManager::class);
        $defs = $this->createMock(Defs::class);
        $defs->method('getEntity')->willReturnCallback(fn ($type) => EntityDefs::fromRaw([
            'attributes' => $type === 'Contact' ? ['tenantId' => ['type' => 'varchar']] : [],
        ], $type));
        $em->method('getDefs')->willReturn($defs);
        $em->method('getRDBRepository')->willReturnCallback(function ($type) {
            $repo = $this->createMock(RDBRepository::class);
            $repo->method('clone')->willReturnCallback(function ($query) use ($type) {
                $raw = $query->getRaw();
                self::assertSame(1, $raw['whereClause']['readable']);
                $allowed = ['Contact' => ['customer'], 'User' => ['location', 'professional'], 'Team' => ['team-a'], 'ChatwootConversation' => ['conversation']][$type];
                $ids = $allowed;
                $constrain = function (array $where) use (&$ids, &$constrain): void {
                    foreach ($where as $key => $value) {
                        if ($key === 'id') $ids = array_intersect($ids, (array) $value);
                        elseif (is_array($value)) $constrain($value);
                    }
                };
                $constrain($raw['whereClause']);
                $builder = $this->createMock(RDBSelectBuilder::class);
                $builder->method('find')->willReturn(new EntityCollection(array_map(function ($id) use ($type) {
                    $record = new BaseEntity($type, ['attributes' => ['id' => ['type' => 'varchar']]]);
                    $record->set('id', $id);
                    return $record;
                }, array_values($ids))));
                return $builder;
            });
            return $repo;
        });
        $acl = $this->createMock(Acl::class);
        $acl->method('checkField')->willReturnCallback(fn ($scope, $field, $action) => $scope === 'Appointment' && $action === 'edit' && $field !== $this->deniedField);
        $factory = $this->createMock(SelectBuilderFactory::class);
        $factory->method('create')->willReturnCallback(function () {
            $type = '';
            $builder = $this->createMock(AclBuilder::class);
            $builder->method('from')->willReturnCallback(function ($value) use (&$type, $builder) { $type = $value; return $builder; });
            $builder->expects(self::once())->method('withStrictAccessControl')->willReturnSelf();
            $builder->method('buildQueryBuilder')->willReturnCallback(function () use (&$type) { return SelectBuilder::create()->from($type)->where(['readable' => 1]); });
            return $builder;
        });
        $this->access = $this->getMockBuilder(Access::class)->setConstructorArgs([
            $em, $acl, $this->createMock(User::class), $this->createMock(UserTenantResolver::class), $factory, $this->createMock(FilterFactory::class),
        ])->onlyMethods(['teamIds'])->getMock();
        $this->access->method('teamIds')->willReturn(['team-a']);
    }

    public function testReferenceQueriesAlwaysCombineStrictAclWithAccountOwnership(): void
    {
        self::assertSame('tenant-a', $this->access->referenceQuery('Contact', $this->tenant)->build()->getRaw()['whereClause']['tenantId']);
        $users = $this->access->referenceQuery('User', $this->tenant)->build()->getRaw();
        self::assertSame(['team-a'], $users['whereClause']['workspaceTeam.id']);
        self::assertTrue($users['whereClause']['isActive']);
        self::assertSame(['team-a'], $this->access->referenceQuery('Team', $this->tenant)->build()->getRaw()['whereClause']['id']);
        self::assertSame('tenant-a', $this->access->referenceQuery('ChatwootConversation', $this->tenant)->build()->getRaw()['whereClause']['chatwootAccount.tenantId']);
        $appointments = $this->access->query('Appointment', $this->tenant)->build()->getRaw();
        self::assertSame(1, $appointments['whereClause']['readable']);
        self::assertSame(['team-a'], $appointments['whereClause']['id=s']->getRaw()['whereClause']['workspaceTeam.id']);
    }

    public function testEverySubmittedReferenceRequiresAuthorizedSameWorkspaceRecordAndEditableField(): void
    {
        $data = (object) ['customerId' => 'customer', 'locationId' => 'location', 'professionalsIds' => ['professional'], 'teamsIds' => ['team-a'], 'chatwootConversationsIds' => ['conversation']];
        $this->access->validateAppointment($data, $this->tenant);
        foreach (['customer', 'location', 'professionals', 'teams', 'chatwootConversations'] as $field) {
            $attribute = $field . (in_array($field, ['customer', 'location'], true) ? 'Id' : 'Ids');
            foreach (['foreign-tenant', 'acl-denied'] as $id) {
                $bad = (object) [$attribute => is_array($data->$attribute) ? [$id] : $id];
                try { $this->access->validateAppointment($bad, $this->tenant); self::fail("$field: $id accepted."); }
                catch (Forbidden) { self::assertTrue(true); }
            }
            $this->deniedField = $field;
            try { $this->access->validateAppointment((object) [$attribute => $data->$attribute], $this->tenant); self::fail(); }
            catch (Forbidden) { self::assertTrue(true); }
            $this->deniedField = null;
        }
    }

    public function testRejectsTaskAssignmentMalformedAndUnboundedReferences(): void
    {
        foreach ([['assignedUserId' => 'viewer'], ['parentType' => 'Contact'], ['assignedUsersIds' => ['viewer']], ['professionalsIds' => 'professional'], ['teamsIds' => [42]], ['professionalsIds' => array_fill(0, 101, 'professional')]] as $data) {
            try { $this->access->validateAppointment((object) $data, $this->tenant); self::fail(); }
            catch (BadRequest) { self::assertTrue(true); }
        }
    }

    public function testNativeHookDerivesAssignedUsersFromLocationAndProfessionals(): void
    {
        $record = new BaseEntity('Appointment', ['attributes' => [
            'locationId' => ['type' => 'varchar'], 'professionalsIds' => ['type' => 'jsonArray'], 'assignedUsersIds' => ['type' => 'jsonArray'],
        ]]);
        $record->set(['locationId' => 'location', 'professionalsIds' => ['professional', 'location'], 'assignedUsersIds' => ['injected']]);
        (new SyncAssignedUsers())->beforeSave($record, SaveOptions::fromAssoc([]));
        self::assertSame(['professional', 'location'], $record->get('assignedUsersIds'));
        $record->set('professionalsIds', []);
        (new SyncAssignedUsers())->beforeSave($record, SaveOptions::fromAssoc([]));
        self::assertSame(['location'], $record->get('assignedUsersIds'));
    }
}

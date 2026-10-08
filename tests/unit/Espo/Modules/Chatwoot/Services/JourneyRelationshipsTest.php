<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\Chatwoot\Services;

use Espo\Core\Acl;
use Espo\Core\Api\Request;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Select\SelectBuilder as AccessSelectBuilder;
use Espo\Core\Select\SelectBuilderFactory;
use Espo\Entities\User;
use Espo\Modules\Chatwoot\Services\JourneyRelationships;
use Espo\Modules\Chatwoot\Controllers\JourneyRelationships as Controller;
use Espo\Modules\Chatwoot\Tools\Activities\Access;
use Espo\Modules\FeatureJourney\Services\JourneyEnrollmentService;
use Espo\Modules\FeatureJourney\Services\TenantGuard;
use Espo\ORM\Entity;
use Espo\ORM\EntityCollection;
use Espo\ORM\EntityManager;
use Espo\ORM\Query\Select;
use Espo\ORM\Query\SelectBuilder;
use Espo\ORM\Repository\RDBRepository;
use Espo\ORM\Repository\RDBSelectBuilder;
use PHPUnit\Framework\TestCase;

class JourneyRelationshipsTest extends TestCase
{
    private JourneyRelationships $service;
    private EntityManager $em;
    private Access $access;
    private TenantGuard $guard;
    private JourneyEnrollmentService $engine;
    private Entity $tenant;
    private Entity $target;
    private array $denied = [];
    private array $forbiddenAttributes = [];
    private array $fixtures = [];
    private array $queries = [];

    private function entity(string $type, string $id, array $attributes = []): Entity
    {
        $entity = $this->createMock(Entity::class);
        $entity->method('getId')->willReturn($id);
        $entity->method('getEntityType')->willReturn($type);
        $entity->method('get')->willReturnCallback(fn (string $key) => $attributes[$key] ?? null);
        return $entity;
    }

    protected function setUp(): void
    {
        $this->tenant = $this->entity('Tenant', 'tenant-a');
        $this->target = $this->entity('Opportunity', 'opportunity-a', ['tenantId' => 'tenant-a', 'name' => 'Deal']);
        $this->fixtures['Opportunity'] = [$this->target];
        $this->em = $this->createMock(EntityManager::class);
        $this->em->method('getEntityById')->willReturnCallback(function ($type, $id) {
            foreach ($this->fixtures[$type] ?? [] as $entity) {
                if ($entity->getId() === $id) return $entity;
            }
            return null;
        });
        $acl = $this->createMock(Acl::class);
        $acl->method('check')->willReturnCallback(fn ($entity, $action) => !in_array($entity->getEntityType() . ':' . $action, $this->denied, true));
        $acl->method('checkScope')->willReturnCallback(fn ($type, $action) => !in_array($type . ':' . $action, $this->denied, true));
        $acl->method('checkField')->willReturnCallback(fn ($type, $field, $action) => !in_array("$type:$field:$action", $this->denied, true));
        $acl->method('getScopeForbiddenAttributeList')->willReturnCallback(fn ($type) => $this->forbiddenAttributes[$type] ?? []);
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn('operator-a');
        $this->access = $this->createMock(Access::class);
        $this->access->method('workspace')->with(6)->willReturn($this->tenant);
        $this->guard = $this->createMock(TenantGuard::class);
        $this->guard->method('entityBelongsToTenant')->willReturnCallback(fn ($entity, $tenantId) => $entity->get('tenantId') === $tenantId);
        $factory = $this->createMock(SelectBuilderFactory::class);
        $factory->method('create')->willReturnCallback(function () {
            $type = null;
            $strict = false;
            $builder = $this->createMock(AccessSelectBuilder::class);
            $builder->method('from')->willReturnCallback(function ($value) use (&$type, $builder) { $type = $value; return $builder; });
            $builder->method('withStrictAccessControl')->willReturnCallback(function () use (&$strict, $builder) { $strict = true; return $builder; });
            $builder->method('buildQueryBuilder')->willReturnCallback(function () use (&$strict, &$type) {
                self::assertTrue($strict, 'All enrollment/option reads must apply strict record ACL.');
                return SelectBuilder::create()->from($type);
            });
            return $builder;
        });
        $this->em->method('getRDBRepository')->willReturnCallback(function ($type) {
            $query = null;
            $repo = $this->createMock(RDBRepository::class);
            $selection = $this->createMock(RDBSelectBuilder::class);
            $repo->method('clone')->willReturnCallback(function (Select $value) use (&$query, $selection, $type) {
                $query = $value->getRaw();
                $this->queries[$type][] = $query;
                self::assertStringContainsString('tenant-a', json_encode($query['whereClause']), 'Every query must be tenant scoped.');
                return $selection;
            });
            $matches = function () use (&$query, $type) {
                $conditions = $this->flatten($query['whereClause']);
                return array_values(array_filter($this->fixtures[$type] ?? [], function ($entity) use ($conditions) {
                    foreach (['tenantId', 'id', 'targetType', 'targetId'] as $field) {
                        if (!array_key_exists($field, $conditions)) continue;
                        $value = $field === 'id' ? $entity->getId() : $entity->get($field);
                        if ($conditions[$field] !== $value) return false;
                    }
                    return true;
                }));
            };
            $selection->method('findOne')->willReturnCallback(fn () => $matches()[0] ?? null);
            $selection->method('find')->willReturnCallback(fn () => new EntityCollection($matches()));
            return $repo;
        });
        $this->engine = $this->createMock(JourneyEnrollmentService::class);
        $this->service = new JourneyRelationships($this->em, $acl, $user, $this->access, $this->guard, $factory, $this->engine);
    }

    private function flatten(array $where): array
    {
        $result = [];
        foreach ($where as $key => $value) {
            if (is_int($key) || $key === 'AND') $result = array_merge($result, $this->flatten($value));
            else $result[$key] = $value;
        }
        return $result;
    }

    private function journey(array $attributes = []): Entity
    {
        $journey = $this->entity('Journey', 'journey-a', array_replace([
            'tenantId' => 'tenant-a', 'targetEntityType' => 'Opportunity', 'status' => 'Active', 'name' => 'Sales',
        ], $attributes));
        $this->fixtures['Journey'] = [$journey];
        return $journey;
    }

    private function enrollment(array $attributes = []): Entity
    {
        $record = $this->entity('JourneyRecord', 'enrollment-a', array_replace([
            'tenantId' => 'tenant-a', 'targetType' => 'Opportunity', 'targetId' => 'opportunity-a',
            'journeyId' => 'journey-a', 'status' => 'Active', 'cycleCount' => 0,
        ], $attributes));
        $this->fixtures['JourneyRecord'] = [$record];
        return $record;
    }

    public function testContextChecksWorkspaceMembership(): void
    {
        $this->access->method('workspace')->willThrowException(new Forbidden());
        $this->em->expects($this->never())->method('getEntityById');
        $this->expectException(Forbidden::class);
        $this->service->context(6, 'Opportunity', 'opportunity-a');
    }

    public function testContextRejectsUnsupportedTypes(): void
    {
        $this->access->expects($this->never())->method('workspace');
        $this->expectException(BadRequest::class);
        $this->service->context(6, 'User', 'admin');
    }

    public function testContextRejectsCrossTenantTarget(): void
    {
        $this->fixtures['Opportunity'] = [$this->entity('Opportunity', 'opportunity-a', ['tenantId' => 'tenant-b'])];
        $this->expectException(NotFound::class);
        $this->service->context(6, 'Opportunity', 'opportunity-a');
    }

    public function testContextRejectsUnreadableTarget(): void
    {
        $this->denied = ['Opportunity:read'];
        $this->expectException(NotFound::class);
        $this->service->context(6, 'Opportunity', 'opportunity-a');
    }

    public function testContextAcceptsAuthorizedTarget(): void
    {
        self::assertSame([$this->tenant, $this->target], $this->service->context(6, 'Opportunity', 'opportunity-a'));
    }

    /** @dataProvider enrollmentPermissions */
    public function testEnrollmentRequiresAllPermissions(string $permission): void
    {
        $this->denied = [$permission];
        $this->engine->expects($this->never())->method('tryEnrollOne');
        $this->expectException(Forbidden::class);
        $this->service->enroll($this->tenant, $this->target, 'journey-a');
    }

    public static function enrollmentPermissions(): array
    {
        return array_map(fn ($value) => [$value], [
            'Opportunity:edit', 'Journey:read', 'Journey:name:read', 'JourneyRecord:read', 'JourneyRecord:create',
            'JourneyRecord:target:read', 'JourneyRecord:target:edit', 'JourneyRecord:journey:edit',
        ]);
    }

    public function testEnrollmentRejectsCrossTenantJourney(): void
    {
        $this->journey(['tenantId' => 'tenant-b']);
        $this->engine->expects($this->never())->method('tryEnrollOne');
        $this->expectException(NotFound::class);
        $this->service->enroll($this->tenant, $this->target, 'journey-a');
    }

    /** @dataProvider incompatibleJourneys */
    public function testEnrollmentRejectsIncompatibleJourney(array $attributes): void
    {
        $this->journey($attributes);
        $this->engine->expects($this->never())->method('tryEnrollOne');
        $this->expectException(BadRequest::class);
        $this->service->enroll($this->tenant, $this->target, 'journey-a');
    }

    public static function incompatibleJourneys(): array
    {
        return [[['targetEntityType' => 'Contact']], [['status' => 'Draft']], [['status' => 'Paused']], [['status' => 'Archived']]];
    }

    public function testEnrollmentUsesEngineIdentityAndDoesNotExposeDiagnostics(): void
    {
        $journey = $this->journey();
        $this->engine->expects($this->once())->method('tryEnrollOne')->with($journey, 'Opportunity', 'opportunity-a', null)
            ->willReturn(['ok' => false, 'reason' => 'on_enter_failed', 'error' => 'private diagnostics']);
        self::assertEquals((object) ['ok' => false, 'reason' => 'on_enter_failed'], $this->service->enroll($this->tenant, $this->target, 'journey-a'));
    }

    /** @dataProvider foreignEnrollments */
    public function testReadAndUnlinkRejectForeignEnrollment(array $attributes, string $action): void
    {
        $this->enrollment($attributes);
        $this->engine->expects($this->never())->method('exitRecord');
        $this->expectException(NotFound::class);
        $this->service->$action($this->tenant, $this->target, 'enrollment-a');
    }

    public static function foreignEnrollments(): array
    {
        $cases = [];
        foreach (['read', 'unlink'] as $action) {
            foreach ([['tenantId' => 'tenant-b'], ['targetId' => 'another-record'], ['targetType' => 'Contact']] as $attributes) {
                $cases[] = [$attributes, $action];
            }
        }
        return $cases;
    }

    /** @dataProvider exitPermissions */
    public function testExitRequiresTargetAndEnrollmentEditAccess(string $permission): void
    {
        $this->enrollment();
        $this->denied = [$permission];
        $this->engine->expects($this->never())->method('exitRecord');
        $this->expectException(Forbidden::class);
        $this->service->unlink($this->tenant, $this->target, 'enrollment-a');
    }

    public static function exitPermissions(): array
    {
        return [['Opportunity:edit'], ['JourneyRecord:edit'], ['JourneyRecord:status:edit']];
    }

    public function testExitPreservesRecordAndUsesOperatorIdentity(): void
    {
        $this->enrollment();
        $exited = $this->entity('JourneyRecord', 'enrollment-a', ['status' => 'Exited']);
        $this->em->expects($this->never())->method('removeEntity');
        $this->engine->expects($this->once())->method('exitRecord')->with('enrollment-a', 'operator-a', 'manual')->willReturn($exited);
        self::assertSame('Exited', $this->service->unlink($this->tenant, $this->target, 'enrollment-a')->status);
    }

    public function testRetryExitDoesNotRepeatLifecycleSideEffects(): void
    {
        $this->enrollment(['status' => 'Exited']);
        $this->engine->expects($this->never())->method('exitRecord');
        self::assertSame('Exited', $this->service->unlink($this->tenant, $this->target, 'enrollment-a')->status);
    }

    public function testFieldLevelReadRestrictionsApplyToAliasesAndRelatedNames(): void
    {
        $this->journey();
        $this->enrollment(['exitReason' => 'secret', 'currentStageId' => 'stage-b']);
        $this->fixtures['JourneyStage'] = [$this->entity('JourneyStage', 'stage-b', ['tenantId' => 'tenant-b', 'name' => 'Foreign stage'])];
        $this->forbiddenAttributes = ['Journey' => ['name'], 'JourneyRecord' => ['exitReason'], 'Opportunity' => ['name']];
        $result = $this->service->read($this->tenant, $this->target, 'enrollment-a');
        self::assertNull($result->name);
        self::assertNull($result->journeyName);
        self::assertNull($result->targetName);
        self::assertNull($result->currentStageName);
        self::assertNull($result->exitReason);
    }

    public function testListDoesNotLeakEnrollmentsForAnotherTargetOrTenant(): void
    {
        $allowed = $this->enrollment();
        $this->fixtures['JourneyRecord'][] = $this->entity('JourneyRecord', 'foreign', ['tenantId' => 'tenant-b', 'targetType' => 'Opportunity', 'targetId' => 'opportunity-a']);
        $this->fixtures['JourneyRecord'][] = $this->entity('JourneyRecord', 'other', ['tenantId' => 'tenant-a', 'targetType' => 'Opportunity', 'targetId' => 'other']);
        $result = $this->service->list($this->tenant, $this->target, 0);
        self::assertSame([$allowed->getId()], array_column($result->list, 'id'));
    }

    public function testOptionsConstrainStatusTypeAndReEnrollmentHistory(): void
    {
        $this->service->options($this->tenant, $this->target, "' OR 1=1 --", 0);
        $where = $this->flatten($this->queries['Journey'][0]['whereClause']);
        self::assertSame('tenant-a', $where['tenantId']);
        self::assertSame('Opportunity', $where['targetEntityType']);
        self::assertSame('Active', $where['status']);
        self::assertSame("%' OR 1=1 --%", $where['name*']);
        $live = $this->flatten($where['id!=s']->getRaw()['whereClause']);
        self::assertSame('tenant-a', $live['tenantId']);
        self::assertSame('Opportunity', $live['targetType']);
        self::assertSame('opportunity-a', $live['targetId']);
        self::assertSame(['Active', 'Processing', 'Paused'], $live['status']);
        self::assertTrue($where['OR'][0]['allowReEnrollment']);
        $history = $this->flatten($where['OR'][1]['id!=s']->getRaw()['whereClause']);
        self::assertSame('opportunity-a', $history['targetId']);
        self::assertArrayNotHasKey('status', $history);
    }

    public function testControllerIgnoresSpoofedTenantTargetAndRunIdentity(): void
    {
        $journey = $this->journey();
        $request = $this->createMock(Request::class);
        $request->method('getQueryParam')->with('accountId')->willReturn('6');
        $request->method('getRouteParam')->willReturnMap([['type', 'Opportunity'], ['targetId', 'opportunity-a']]);
        $request->method('getParsedBody')->willReturn((object) [
            'journeyId' => 'journey-a', 'tenantId' => 'tenant-b', 'targetType' => 'User',
            'targetId' => 'admin', 'runAsUserId' => 'admin', 'currentStageId' => 'privileged-stage', 'status' => 'Active',
        ]);
        $this->engine->expects($this->once())->method('tryEnrollOne')->with($journey, 'Opportunity', 'opportunity-a', null)->willReturn(['ok' => true]);
        self::assertTrue((new Controller($this->service))->postActionEnroll($request)->ok);
    }

    public function testControllerRejectsMalformedWorkspaceBeforeAccess(): void
    {
        $request = $this->createMock(Request::class);
        $request->method('getQueryParam')->with('accountId')->willReturn('6invalid');
        $this->access->expects($this->never())->method('workspace');
        $this->expectException(BadRequest::class);
        (new Controller($this->service))->getActionList($request);
    }

    public function testListWithoutReadAccessDoesNotQueryEnrollmentData(): void
    {
        $this->denied = ['JourneyRecord:read'];
        $this->em->expects($this->never())->method('getRDBRepository');
        self::assertEquals((object) ['list' => [], 'hasMore' => false, 'canEnroll' => false], $this->service->list($this->tenant, $this->target, 0));
    }
}

<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureCredits;

use Espo\Core\Acl;
use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Entities\User;
use Espo\Modules\FeatureAiUsage\Services\Access;
use Espo\Modules\FeatureCredits\Controllers\CreditOperationSource;
use Espo\Modules\FeatureCredits\Services\OperationSource;
use Espo\Modules\Global\Tools\Tenant\TenantResolver;
use Espo\Modules\Global\Tools\Tenant\UserTenantResolver;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class OperationSourceTest extends TestCase
{
    private function record(array $fields): Entity
    {
        $entity = $this->createMock(Entity::class);
        $entity->method('get')->willReturnCallback(static fn ($field) => $fields[$field] ?? null);
        return $entity;
    }

    public static function sourceCases(): iterable
    {
        foreach (['ChatwootAiAgentRun', 'Opportunity'] as $scope) {
            yield $scope => [$scope, 'allowed'];
            yield "$scope hidden name" => [$scope, 'name-denied'];
        }
        foreach (['scope-denied', 'record-denied', 'missing', 'deleted', 'foreign', 'unattributed',
            'bad-id', 'empty-id', 'missing-id'] as $case) {
            yield $case => ['ChatwootAiAgentRun', $case];
        }
        foreach (['User', 'CreditUsage', 'Note', 'Unknown', null] as $scope) {
            yield 'unsupported ' . ($scope ?? 'null') => [$scope, 'unsupported'];
        }
    }

    #[DataProvider('sourceCases')]
    public function testSourcePermissionsAndAllowlist(?string $scope, string $case): void
    {
        $tenant = $this->record([]);
        $usage = $this->record(['tenantId' => 'tenant', 'sourceType' => $scope,
            'sourceId' => match ($case) {
                'bad-id' => '../secret', 'empty-id' => '', 'missing-id' => null, default => 'source',
            }, 'evidence' => 'private evidence', 'executionId' => 'secret']);
        $source = $case === 'missing' ? null : $this->record([
            'tenantId' => match ($case) { 'foreign' => 'other', 'unattributed' => null, default => 'tenant' },
            'name' => 'Sensitive name', 'deleted' => $case === 'deleted',
        ]);
        $canLookup = !in_array($case, ['unsupported', 'bad-id', 'empty-id', 'missing-id', 'scope-denied'], true);
        $manager = $this->createMock(EntityManager::class);
        $manager->expects($this->exactly($canLookup ? 3 : 2))->method('getEntityById')
            ->willReturnCallback(function ($type, $id) use ($tenant, $usage, $source, $scope, $canLookup) {
                if ($type === 'Tenant' && $id === 'tenant') return $tenant;
                if ($type === 'CreditUsage' && $id === 'operation') return $usage;
                $this->assertTrue($canLookup);
                $this->assertSame($scope, $type);
                $this->assertSame('source', $id);
                return $source;
            });
        $acl = $this->createMock(Acl::class);
        $acl->method('checkScope')->with($scope, 'read')->willReturn($case !== 'scope-denied');
        $acl->method('check')->with($source, 'read')->willReturn($case !== 'record-denied');
        $acl->method('checkField')->with($scope, 'name')->willReturn($case !== 'name-denied');
        $result = (new OperationSource($manager, $acl))->inspect('tenant', 'operation');
        $expected = in_array($case, ['allowed', 'name-denied'], true)
            ? ['scope' => $scope, 'id' => 'source', 'name' => $case === 'allowed' ? 'Sensitive name' : null] : null;
        $this->assertSame(['tenantId' => 'tenant', 'id' => 'operation', 'source' => $expected], $result);
    }

    public static function invalidParents(): array
    {
        return [['missing-tenant'], ['deleted-tenant'], ['missing-usage'], ['deleted-usage'], ['foreign-usage']];
    }

    #[DataProvider('invalidParents')]
    public function testUnknownAndForeignOperationsDoNotResolveSources(string $case): void
    {
        $manager = $this->createMock(EntityManager::class);
        $manager->expects($this->exactly(str_ends_with($case, 'tenant') ? 1 : 2))->method('getEntityById')
            ->willReturnCallback(fn ($scope) => $scope === 'Tenant'
                ? ($case === 'missing-tenant' ? null : $this->record(['deleted' => $case === 'deleted-tenant']))
                : ($case === 'missing-usage' ? null : $this->record([
                    'tenantId' => $case === 'foreign-usage' ? 'other' : 'tenant', 'deleted' => $case === 'deleted-usage',
                ])));
        $acl = $this->createMock(Acl::class);
        $acl->expects($this->never())->method('checkScope');
        $this->expectException(NotFound::class);
        (new OperationSource($manager, $acl))->inspect('tenant', 'operation');
    }

    public static function invalidQueries(): iterable
    {
        yield [[]];
        foreach (['tenantId', 'id'] as $field) {
            foreach ([null, [], 1, '', ' bad', '../id', str_repeat('a', 25)] as $value) {
                yield [array_replace(['tenantId' => 'tenant', 'id' => 'operation'], [$field => $value])];
            }
        }
    }

    #[DataProvider('invalidQueries')]
    public function testInvalidQueryStopsBeforeAccessAndReads(array $query): void
    {
        $access = $this->createMock(Access::class);
        $access->expects($this->never())->method('assertTenant');
        $manager = $this->createMock(EntityManager::class);
        $manager->expects($this->never())->method('getEntityById');
        $this->expectException(BadRequest::class);
        $this->invoke($query, $access, $manager);
    }

    public static function deniedUsers(): array
    {
        return [['member'], ['portal'], ['api'], ['foreign-admin'], ['missing-tenant']];
    }

    #[DataProvider('deniedUsers')]
    public function testFinancialAuthorizationBeforeSourceReads(string $kind): void
    {
        $user = $this->createMock(User::class);
        $user->method('isPortal')->willReturn($kind === 'portal');
        $user->method('isApi')->willReturn($kind === 'api');
        $user->method('isAdmin')->willReturn(in_array($kind, ['portal', 'api', 'missing-tenant'], true));
        $user->method('getLinkMultipleIdList')->willReturn($kind === 'foreign-admin' ? [md5('tenant-admin')] : []);
        $memberships = $this->createMock(UserTenantResolver::class);
        $memberships->method('resolveTenantIds')->willReturn(['other']);
        $manager = $this->createMock(EntityManager::class);
        // The access policy may inspect Tenant but must never read accounting or source entities.
        $manager->method('getEntityById')->willReturnCallback(function ($scope) {
            $this->assertSame('Tenant', $scope);
            return null;
        });
        $access = new Access($user, $manager, $this->createMock(TenantResolver::class), $memberships);
        $this->expectException(Forbidden::class);
        $this->invoke(['tenantId' => 'tenant', 'id' => 'operation'], $access, $manager);
    }

    public function testAuthorizedControllerAndRoute(): void
    {
        $access = $this->createMock(Access::class);
        $access->expects($this->once())->method('assertTenant')->with('tenant');
        $manager = $this->createMock(EntityManager::class);
        $manager->method('getEntityById')->willReturnCallback(fn ($scope) => $this->record(
            $scope === 'CreditUsage' ? ['tenantId' => 'tenant'] : []));
        $this->assertEquals((object) ['tenantId' => 'tenant', 'id' => 'operation', 'source' => null],
            $this->invoke(['tenantId' => 'tenant', 'id' => 'operation'], $access, $manager));
        $root = 'custom/Espo/Modules/FeatureCredits/Resources/';
        $routes = json_decode(file_get_contents($root . 'routes.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame([['route' => '/CreditOperationSource', 'method' => 'get',
            'params' => ['controller' => 'CreditOperationSource', 'action' => 'read']]],
            array_values(array_filter($routes, static fn ($r) => $r['route'] === '/CreditOperationSource')));
        $scope = json_decode(file_get_contents($root . 'metadata/scopes/CreditOperationSource.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertFalse($scope['entity']);
    }

    public function testNavigationRechecksPermissionsInsteadOfCachingSource(): void
    {
        $manager = $this->createMock(EntityManager::class);
        $manager->expects($this->exactly(6))->method('getEntityById')->willReturnCallback(fn ($scope) =>
            $this->record(match ($scope) {
                'CreditUsage' => ['tenantId' => 'tenant', 'sourceType' => 'Opportunity', 'sourceId' => 'source'],
                'Opportunity' => ['tenantId' => 'tenant', 'name' => 'Name'],
                default => [],
            }));
        $acl = $this->createMock(Acl::class);
        $acl->method('checkScope')->willReturn(true);
        $acl->expects($this->exactly(2))->method('check')->willReturnOnConsecutiveCalls(true, false);
        $acl->expects($this->once())->method('checkField')->with('Opportunity', 'name')->willReturn(true);
        $service = new OperationSource($manager, $acl);
        $this->assertSame(['scope' => 'Opportunity', 'id' => 'source', 'name' => 'Name'],
            $service->inspect('tenant', 'operation')['source']);
        $this->assertNull($service->inspect('tenant', 'operation')['source']);
    }

    private function invoke(array $query, Access $access, EntityManager $manager): object
    {
        $request = $this->createMock(Request::class);
        $request->method('getQueryParams')->willReturn($query);
        $response = $this->createMock(Response::class);
        $response->expects($this->once())->method('setHeader')->with('Cache-Control', 'private, no-store');
        return (new CreditOperationSource($access, new OperationSource($manager, $this->createMock(Acl::class))))
            ->getActionRead($request, $response);
    }
}

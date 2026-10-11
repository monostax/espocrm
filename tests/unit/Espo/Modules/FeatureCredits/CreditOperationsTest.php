<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureCredits;

use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Entities\User;
use Espo\Modules\FeatureAiUsage\Services\Access;
use Espo\Modules\FeatureCredits\Accounting\Clock;
use Espo\Modules\FeatureCredits\Accounting\OperationHistory;
use Espo\Modules\FeatureCredits\Accounting\OperationHistoryQuery;
use Espo\Modules\FeatureCredits\Accounting\ReservationHistoryQuery;
use Espo\Modules\FeatureCredits\Controllers\CreditOperations;
use Espo\Modules\Global\Tools\Tenant\TenantResolver;
use Espo\Modules\Global\Tools\Tenant\UserTenantResolver;
use Espo\ORM\EntityManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CreditOperationsTest extends TestCase
{
    public function testAuthenticatedNonEntityRoute(): void
    {
        $root = 'custom/Espo/Modules/FeatureCredits/Resources/';
        $routes = json_decode(file_get_contents($root . 'routes.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame([['route' => '/CreditOperations', 'method' => 'get',
            'params' => ['controller' => 'CreditOperations', 'action' => 'list']]],
            array_values(array_filter($routes, static fn ($r) => $r['route'] === '/CreditOperations')));
        $scope = json_decode(file_get_contents($root . 'metadata/scopes/CreditOperations.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertFalse($scope['entity']);
        $this->assertSame('FeatureCredits', $scope['module']);
    }

    public static function invalidQueries(): iterable
    {
        foreach ([[], ['tenantId' => ''], ['tenantId' => ['tenant']], ['tenantId' => ' tenant']] as $query) {
            yield [$query];
        }
        foreach (['0', '101', '-1', '01', '1.0', '1e2', ' 1', [], 1] as $limit) {
            yield [['tenantId' => 'tenant', 'limit' => $limit]];
        }
        foreach (['', 'garbage', [], null, str_repeat('a', 257),
            OperationHistoryQuery::cursor('other', '2026-10-10 12:00:00', 'id'),
            OperationHistoryQuery::cursor('tenant', '2026-02-30 12:00:00', 'id'),
            OperationHistoryQuery::cursor('tenant', '2026-10-10 12:00:00', 'bad id'),
            OperationHistoryQuery::cursor('tenant', '2026-10-10 12:00:00', 'id') . '=',
            ReservationHistoryQuery::cursor('tenant', '2026-10-10 12:00:00', 'id')] as $cursor) {
            yield [['tenantId' => 'tenant', 'cursor' => $cursor]];
        }
    }

    #[DataProvider('invalidQueries')]
    public function testInvalidQueryNeverReadsAccounting(array $query): void
    {
        $access = $this->createMock(Access::class);
        $access->expects($this->never())->method('assertTenant');
        $manager = $this->createMock(EntityManager::class);
        $manager->expects($this->never())->method('getPDO');
        $this->expectException(BadRequest::class);
        $this->invoke($query, $access, $manager);
    }

    public static function deniedUsers(): array
    {
        return [['member'], ['portal'], ['api'], ['foreign-admin'], ['missing-tenant']];
    }

    #[DataProvider('deniedUsers')]
    public function testAuthorizationBeforeAccounting(string $kind): void
    {
        $user = $this->createMock(User::class);
        $user->method('isPortal')->willReturn($kind === 'portal');
        $user->method('isApi')->willReturn($kind === 'api');
        $user->method('isAdmin')->willReturn(in_array($kind, ['portal', 'api', 'missing-tenant'], true));
        $user->method('getLinkMultipleIdList')->willReturn($kind === 'foreign-admin' ? [md5('tenant-admin')] : []);
        $memberships = $this->createMock(UserTenantResolver::class);
        $memberships->method('resolveTenantIds')->willReturn(['other']);
        $manager = $this->createMock(EntityManager::class);
        $manager->expects($this->never())->method('getPDO');
        $access = new Access($user, $manager, $this->createMock(TenantResolver::class), $memberships);
        $this->expectException(Forbidden::class);
        $this->invoke(['tenantId' => 'tenant'], $access, $manager);
    }

    private function invoke(array $query, Access $access, EntityManager $manager): object
    {
        $request = $this->createMock(Request::class);
        $request->method('getQueryParams')->willReturn($query);
        $response = $this->createMock(Response::class);
        $response->expects($this->once())->method('setHeader')->with('Cache-Control', 'private, no-store');
        return (new CreditOperations($access, new OperationHistory($manager, new Clock())))->getActionList($request, $response);
    }
}

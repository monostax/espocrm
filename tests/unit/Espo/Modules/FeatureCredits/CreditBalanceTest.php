<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureCredits;

use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Entities\User;
use Espo\Modules\FeatureAiUsage\Services\Access;
use Espo\Modules\FeatureCredits\Accounting\BalanceStatus;
use Espo\Modules\FeatureCredits\Accounting\Clock;
use Espo\Modules\FeatureCredits\Controllers\CreditBalance;
use Espo\Modules\Global\Tools\Tenant\TenantResolver;
use Espo\Modules\Global\Tools\Tenant\UserTenantResolver;
use Espo\ORM\EntityManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CreditBalanceTest extends TestCase
{
    public static function invalidQueries(): array
    {
        return [[[]], [['tenantId' => '']], [['tenantId' => ['tenant']]],
            [['tenantId' => 123]], [['tenantId' => ' tenant']], [['tenantId' => str_repeat('a', 256)]]];
    }

    #[DataProvider('invalidQueries')]
    public function testExplicitValidTenantRequiredBeforeAuthorization(array $query): void
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
    public function testDeniedUsersNeverReadAccounting(string $kind): void
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
        return (new CreditBalance($access, new BalanceStatus($manager, new Clock())))->getActionStatus($request, $response);
    }
}

<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureCredits;

use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Modules\FeatureAiUsage\Services\Access;
use Espo\Modules\FeatureCredits\Controllers\Credits;
use Espo\Entities\User;
use Espo\ORM\EntityManager;
use Espo\Modules\Global\Tools\Tenant\TenantResolver;
use Espo\Modules\Global\Tools\Tenant\UserTenantResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CreditsTest extends TestCase
{
    public static function deniedUsers(): array
    {
        return [['member'], ['portal'], ['api']];
    }

    #[DataProvider('deniedUsers')]
    public function testNoFinancialAccessExposesNoTenantNames(string $kind): void
    {
        $user = $this->createMock(User::class);
        $user->method('isPortal')->willReturn($kind === 'portal');
        $user->method('isApi')->willReturn($kind === 'api');
        $user->method('isAdmin')->willReturn($kind !== 'member');
        $user->method('getLinkMultipleIdList')->willReturn([]);
        $user->method('getTeamIdList')->willReturn([]);
        $manager = $this->createMock(EntityManager::class);
        $manager->expects($this->never())->method('getRDBRepository');
        $access = new Access($user, $manager, $this->createMock(TenantResolver::class), $this->createMock(UserTenantResolver::class));
        $this->assertEquals((object) ['tenants' => []], (new Credits($access))->getActionContext(
            $this->createMock(Request::class), $this->createMock(Response::class)));
    }

    public function testContextUsesFinancialPolicyTenantListAndPrivateHeader(): void
    {
        $access = $this->createMock(Access::class);
        $access->expects($this->once())->method('tenants')->willReturn([['id' => 'allowed', 'name' => 'Allowed']]);
        $response = $this->createMock(Response::class);
        $response->expects($this->once())->method('setHeader')->with('Cache-Control', 'private, no-store');
        $this->assertEquals((object) ['tenants' => [['id' => 'allowed', 'name' => 'Allowed']]],
            (new Credits($access))->getActionContext($this->createMock(Request::class), $response));
        $routes = json_decode(file_get_contents('custom/Espo/Modules/FeatureCredits/Resources/routes.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame([['route' => '/Credits/context', 'method' => 'get', 'params' => ['controller' => 'Credits', 'action' => 'context']]],
            array_values(array_filter($routes, static fn ($r) => $r['route'] === '/Credits/context')));
    }
}

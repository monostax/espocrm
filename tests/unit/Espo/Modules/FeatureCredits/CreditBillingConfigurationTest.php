<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureCredits;

use Espo\Core\Api\{Request, Response};
use Espo\Core\Exceptions\{BadRequest, Forbidden};
use Espo\Entities\User;
use Espo\Modules\FeatureCredits\Accounting\Clock;
use Espo\Modules\FeatureCredits\Billing\Catalog;
use Espo\Modules\FeatureCredits\Controllers\CreditBillingConfiguration;
use Espo\ORM\EntityManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/BillingFixtures.php';

final class CreditBillingConfigurationTest extends TestCase
{
    private function controller(bool $admin): CreditBillingConfiguration
    {
        $user = $this->createStub(User::class); $user->method('isAdmin')->willReturn($admin);
        $user->method('getId')->willReturn('admin');
        $manager = $this->createMock(EntityManager::class); $manager->expects($this->never())->method('getPDO');
        return new CreditBillingConfiguration($user, new Catalog($manager, $this->createStub(Clock::class)));
    }

    public static function actions(): iterable
    {
        yield ['getActionRead'];
        yield ['postActionPublish'];
    }

    #[DataProvider('actions')]
    public function testNonInstanceAdminsCannotAccessGlobalCommercialConfiguration(string $action): void
    {
        $request = $this->createMock(Request::class);
        $request->expects($this->never())->method('getBodyContents');
        $request->expects($this->never())->method('getQueryParams');
        $response = $this->createMock(Response::class);
        $response->expects($this->once())->method('setHeader')->with('Cache-Control', 'private, no-store');
        $this->expectException(Forbidden::class);
        $this->controller(false)->$action($request, $response);
    }

    public function testMalformedAndForgedPublicationFailsBeforePersistence(): void
    {
        $forged = BillingFixtures::definition(); $forged->actorId = 'other';
        foreach (['null', '{}', '{', '[]', str_repeat(' ', 65537), json_encode($forged, JSON_THROW_ON_ERROR)] as $raw) {
            $request = $this->createStub(Request::class); $request->method('getBodyContents')->willReturn($raw);
            try {
                $this->controller(true)->postActionPublish($request, $this->createStub(Response::class));
                $this->fail('Invalid publication accepted.');
            } catch (BadRequest) { $this->addToAssertionCount(1); }
        }
    }

    public function testMalformedReadFailsBeforePersistence(): void
    {
        foreach ([['tenantId' => 'tenant'], ['catalogId' => []], ['catalogId' => 'latest'], ['catalogId' => null]] as $query) {
            $request = $this->createStub(Request::class); $request->method('getQueryParams')->willReturn($query);
            try {
                $this->controller(true)->getActionRead($request, $this->createStub(Response::class));
                $this->fail('Invalid read accepted.');
            } catch (BadRequest) { $this->addToAssertionCount(1); }
        }
    }

    public function testRoutesRequireNormalCrmAuthentication(): void
    {
        $routes = json_decode(file_get_contents('custom/Espo/Modules/FeatureCredits/Resources/routes.json'), true, 32, JSON_THROW_ON_ERROR);
        $routes = array_values(array_filter($routes, static fn ($r) => ($r['params']['controller'] ?? '') === 'CreditBillingConfiguration'));
        $this->assertCount(2, $routes);
        foreach ($routes as $route) {
            $this->assertSame('/CreditBillingConfiguration', $route['route']);
            $this->assertFalse($route['noAuth'] ?? false);
        }
    }
}

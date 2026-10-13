<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureCredits;

use Espo\Core\Api\{Request, Response};
use Espo\Core\Exceptions\{BadRequest, Forbidden};
use Espo\Entities\User;
use Espo\Modules\FeatureCredits\Accounting\{Clock, Funding, LegacyMigration, WalletLock};
use Espo\Modules\FeatureCredits\Controllers\CreditMigration;
use Espo\ORM\EntityManager;
use PHPUnit\Framework\TestCase;

final class CreditMigrationTest extends TestCase
{
    private function controller(bool $admin): CreditMigration
    {
        $user = $this->createStub(User::class);
        $user->method('isAdmin')->willReturn($admin);
        $user->method('getId')->willReturn('admin');
        $manager = $this->createMock(EntityManager::class);
        $manager->expects($this->never())->method('getPDO');
        $lock = new WalletLock($manager, $this->createStub(Clock::class));
        return new CreditMigration($user, new LegacyMigration($lock, new Funding($lock)));
    }

    public function testOnlyInstanceAdministratorCanGrant(): void
    {
        $request = $this->createMock(Request::class);
        $request->expects($this->never())->method('getBodyContents');
        $response = $this->createMock(Response::class);
        $response->expects($this->once())->method('setHeader')->with('Cache-Control', 'private, no-store');
        $this->expectException(Forbidden::class);
        $this->controller(false)->postActionGrant($request, $response);
    }

    public function testExplicitTermsAndServerActorRequired(): void
    {
        $valid = ['tenantId' => 'tenant', 'cutoverId' => 'cutover', 'credits' => '10', 'expiresAt' => null,
            'legacyReference' => 'legacy:1', 'approvalReference' => 'approval:1'];
        $bodies = ['null', '{}', '[]', '{', str_repeat(' ', 4097)];
        foreach ([['actorId' => 'victim'], ['credits' => 10], ['credits' => '-1'], ['credits' => '0'],
            ['credits' => '0.00001'], ['legacyReference' => ''], ['approvalReference' => "bad\nreference"],
            ['tenantId' => '../other'], ['expiresAt' => 'tomorrow'], ['cutoverId' => null]] as $change) {
            $bodies[] = json_encode(array_replace($valid, $change), JSON_THROW_ON_ERROR);
        }
        unset($valid['expiresAt']); $bodies[] = json_encode($valid);
        foreach ($bodies as $raw) {
            $request = $this->createStub(Request::class);
            $request->method('getBodyContents')->willReturn($raw);
            try { $this->controller(true)->postActionGrant($request, $this->createStub(Response::class)); $this->fail('Invalid migration accepted.'); }
            catch (BadRequest) { $this->addToAssertionCount(1); }
        }
    }

    public function testAuthenticatedRouteHasErrorAndSuccessPrivacy(): void
    {
        $root = 'custom/Espo/Modules/FeatureCredits/Resources/';
        $routes = json_decode(file_get_contents($root . 'routes.json'), true);
        $routes = array_values(array_filter($routes, static fn ($r) => ($r['params']['controller'] ?? '') === 'CreditMigration'));
        $this->assertCount(1, $routes);
        $this->assertSame('/CreditMigration', $routes[0]['route']);
        $this->assertFalse($routes[0]['noAuth'] ?? false);
        $api = json_decode(file_get_contents($root . 'metadata/app/api.json'), true);
        $this->assertSame($api['controllerMiddlewareClassNameListMap']['CreditMigration'],
            $api['routeMiddlewareClassNameListMap']['post_/CreditMigration']);
    }
}

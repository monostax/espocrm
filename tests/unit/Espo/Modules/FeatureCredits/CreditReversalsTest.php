<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureCredits;

use Espo\Core\Api\{Request, Response};
use Espo\Core\Exceptions\{BadRequest, Forbidden};
use Espo\Entities\User;
use Espo\Modules\FeatureCredits\Accounting\{Clock, Funding, Reversals, WalletLock};
use Espo\Modules\FeatureCredits\Controllers\CreditReversals;
use Espo\ORM\EntityManager;
use PHPUnit\Framework\TestCase;

final class CreditReversalsTest extends TestCase
{
    private function controller(bool $admin): CreditReversals
    {
        $user = $this->createStub(User::class);
        $user->method('isAdmin')->willReturn($admin);
        $user->method('getId')->willReturn('admin');
        $manager = $this->createMock(EntityManager::class);
        $manager->expects($this->never())->method('getPDO');
        $lock = new WalletLock($manager, $this->createStub(Clock::class));
        return new CreditReversals($user, new Reversals($lock, new Funding($lock)));
    }

    public function testNonInstanceAdminDeniedBeforeReadingInput(): void
    {
        $request = $this->createMock(Request::class);
        $request->expects($this->never())->method('getBodyContents');
        $response = $this->createMock(Response::class);
        $response->expects($this->once())->method('setHeader')->with('Cache-Control', 'private, no-store');
        $this->expectException(Forbidden::class);
        $this->controller(false)->postActionReverse($request, $response);
    }

    public function testMalformedAmountsIdentitiesAndForgedActorRejectedBeforePersistence(): void
    {
        $valid = ['tenantId' => 'tenant', 'transactionId' => 'debit', 'idempotencyKey' => 'correction',
            'credits' => '0.1', 'reason' => 'Correction'];
        $bodies = ['null', '{}', '[]', '{', str_repeat(' ', 4097)];
        foreach ([['actorId' => 'victim'], ['credits' => 0.1], ['credits' => '-1'], ['credits' => '0'],
            ['credits' => '0.00001'], ['reason' => ''], ['reason' => "bad\nreason"], ['tenantId' => '../other'],
            ['transactionId' => null], ['idempotencyKey' => 'UPPER']] as $change) {
            $bodies[] = json_encode(array_replace($valid, $change), JSON_THROW_ON_ERROR);
        }
        foreach ($bodies as $raw) {
            $request = $this->createStub(Request::class);
            $request->method('getBodyContents')->willReturn($raw);
            try { $this->controller(true)->postActionReverse($request, $this->createStub(Response::class)); $this->fail('Invalid reversal accepted.'); }
            catch (BadRequest) { $this->addToAssertionCount(1); }
        }
    }

    public function testReversalRouteRequiresCrmAuthentication(): void
    {
        $routes = json_decode(file_get_contents('custom/Espo/Modules/FeatureCredits/Resources/routes.json'), true, 32, JSON_THROW_ON_ERROR);
        $routes = array_values(array_filter($routes, static fn ($r) => ($r['params']['controller'] ?? '') === 'CreditReversals'));
        $this->assertCount(1, $routes);
        $this->assertSame('/CreditReversals', $routes[0]['route']);
        $this->assertSame('post', $routes[0]['method']);
        $this->assertFalse($routes[0]['noAuth'] ?? false);
    }
}

<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureCredits;

use Espo\Core\Api\{Request, Response};
use Espo\Core\Exceptions\{BadRequest, Forbidden};
use Espo\Core\Utils\{Config, Crypt};
use Espo\Entities\User;
use Espo\Modules\FeatureAiUsage\Services\Access;
use Espo\Modules\FeatureCredits\Accounting\Clock;
use Espo\Modules\FeatureCredits\Billing\{Accounts, Catalog, CreationStore, Providers, Subscriptions};
use Espo\Modules\FeatureCredits\Billing\Asaas\{Connections, Transport};
use Espo\Modules\FeatureCredits\Controllers\CreditSubscriptions;
use Espo\Modules\Global\Tools\Tenant\{TenantResolver, UserTenantResolver};
use Espo\ORM\EntityManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CreditSubscriptionsTest extends TestCase
{
    public static function denied(): iterable
    {
        foreach (['Cancel', 'Reconcile'] as $action) foreach (['member', 'portal', 'api', 'foreign-admin'] as $kind) yield "$action $kind" => [$action, $kind];
    }

    #[DataProvider('denied')]
    public function testAuthorizationBeforeProviderOrDatabase(string $action, string $kind): void
    {
        $user = $this->createStub(User::class);
        $user->method('isPortal')->willReturn($kind === 'portal'); $user->method('isApi')->willReturn($kind === 'api');
        $user->method('getLinkMultipleIdList')->willReturn($kind === 'foreign-admin' ? [md5('tenant-admin')] : []);
        $manager = $this->createMock(EntityManager::class); $manager->expects($this->never())->method('getPDO');
        $memberships = $this->createStub(UserTenantResolver::class); $memberships->method('resolveTenantIds')->willReturn(['other']);
        $access = new Access($user, $manager, $this->createStub(TenantResolver::class), $memberships);
        $clock = $this->createStub(Clock::class); $config = $this->createStub(Config::class);
        $config->method('get')->willReturn('subscription-test-key');
        $connections = new Connections($config);
        $accounts = new Accounts($manager, $clock, new Catalog($manager, $clock), new Providers($config, $connections));
        $transport = $this->createMock(Transport::class); $transport->expects($this->never())->method('send');
        $service = new Subscriptions($accounts, new CreationStore($accounts, new Crypt($config), $connections), $connections, $transport);
        $controller = new CreditSubscriptions($user, $access, $service);
        $input = ['tenantId' => 'tenant', 'obligationId' => 'obligation'];
        if ($action === 'Cancel') $input['requestKey'] = 'stop';
        $response = $this->createMock(Response::class);
        $response->expects($this->exactly(2))->method('setHeader')->with('Cache-Control', 'private, no-store');
        $forged = $this->createStub(Request::class); $forged->method('getBodyContents')->willReturn(json_encode($input + ['actorId' => 'forged']));
        try { $controller->{'postAction' . $action}($forged, $response); $this->fail('Forged actor accepted.'); } catch (BadRequest) {}
        $request = $this->createStub(Request::class); $request->method('getBodyContents')->willReturn(json_encode($input));
        $this->expectException(Forbidden::class);
        $controller->{'postAction' . $action}($request, $response);
    }
}

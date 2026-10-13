<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureCredits;

use Espo\Core\Api\{Request, Response};
use Espo\Core\Exceptions\{BadRequest, Forbidden};
use Espo\Core\Utils\{Config, Crypt};
use Espo\Entities\User;
use Espo\Modules\FeatureAiUsage\Services\Access;
use Espo\Modules\FeatureCredits\Accounting\Clock;
use Espo\Modules\FeatureCredits\Billing\{Accounts, Catalog, Creation, CreationStore, Providers};
use Espo\Modules\FeatureCredits\Billing\Asaas\{Adapter, Connections, Transport};
use Espo\Modules\FeatureCredits\Controllers\CreditBilling;
use Espo\Modules\Global\Tools\Tenant\{TenantResolver, UserTenantResolver};
use Espo\ORM\{Entity, EntityManager};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CreditBillingTest extends TestCase
{
    public static function commands(): array
    {
        return [
            'getActionContext' => ['tenantId' => 'tenant'],
            'getActionObligation' => ['tenantId' => 'tenant', 'obligationId' => 'obligation'],
            'postActionCountry' => ['tenantId' => 'tenant', 'requestKey' => 'country', 'revision' => 0, 'billingCountry' => 'BR'],
            'postActionOverride' => ['tenantId' => 'tenant', 'requestKey' => 'override', 'revision' => 0, 'providerOverride' => 'asaas'],
            'postActionAccept' => ['tenantId' => 'tenant', 'requestKey' => 'buy', 'revision' => 1, 'catalogId' => 'catalog', 'offerId' => 'offer', 'termsId' => 'terms'],
            'postActionCancel' => ['tenantId' => 'tenant', 'requestKey' => 'cancel', 'obligationId' => 'obligation'],
            'postActionCustomer' => ['tenantId' => 'tenant', 'obligationId' => 'obligation', 'payer' =>
                ['name' => 'Sandbox Payer', 'cpfCnpj' => '52998224725', 'email' => null, 'notificationDisabled' => true]],
            'postActionCharge' => ['tenantId' => 'tenant', 'obligationId' => 'obligation', 'customerCreationId' => 'customer', 'billingType' => 'PIX', 'dueDate' => '2026-10-15'],
            'postActionRecoverCreation' => ['tenantId' => 'tenant', 'creationId' => 'creation'],
        ];
    }

    public static function deniedUsers(): iterable
    {
        foreach (self::commands() as $action => $input) {
            foreach (['member', 'portal', 'api', 'foreign-admin', 'missing-tenant'] as $kind) yield "$action $kind" => [$action, $input, $kind];
        }
    }

    #[DataProvider('deniedUsers')]
    public function testRealFinancialAuthorizationDeniesBeforeBillingAccess(string $action, array $input, string $kind): void
    {
        $user = $this->createStub(User::class);
        $user->method('isPortal')->willReturn($kind === 'portal'); $user->method('isApi')->willReturn($kind === 'api');
        $user->method('isAdmin')->willReturn(in_array($kind, ['portal', 'api', 'missing-tenant'], true));
        $user->method('getLinkMultipleIdList')->willReturn($kind === 'foreign-admin' ? [md5('tenant-admin')] : []);
        $memberships = $this->createStub(UserTenantResolver::class); $memberships->method('resolveTenantIds')->willReturn(['other']);
        $manager = $this->createMock(EntityManager::class); $manager->expects($this->never())->method('getPDO');
        $access = new Access($user, $manager, $this->createStub(TenantResolver::class), $memberships);
        $this->expectException(Forbidden::class);
        $this->invoke($this->controller($user, $manager, $access), $action, $input);
    }

    public function testOwnTenantAdministratorStillCannotEditProviderOverride(): void
    {
        $user = $this->createStub(User::class); $user->method('getLinkMultipleIdList')->willReturn([md5('tenant-admin')]);
        $manager = $this->createMock(EntityManager::class); $manager->expects($this->never())->method('getPDO');
        $manager->method('getEntityById')->willReturn($this->createStub(Entity::class));
        $memberships = $this->createStub(UserTenantResolver::class); $memberships->method('resolveTenantIds')->willReturn(['tenant']);
        $access = new Access($user, $manager, $this->createStub(TenantResolver::class), $memberships);
        $access->assertTenant('tenant');
        $this->expectException(Forbidden::class);
        $this->invoke($this->controller($user, $manager, $access), 'postActionOverride', self::commands()['postActionOverride']);
    }

    public static function invalidCommands(): iterable
    {
        foreach (self::commands() as $action => $input) {
            yield "$action extra actor" => [$action, $input + ['actorId' => 'forged']];
            yield "$action client payment" => [$action, $input + ['paid' => true]];
            yield "$action extra provider" => [$action, $input + ['provider' => 'asaas']];
            yield "$action invalid tenant" => [$action, array_replace($input, ['tenantId' => ['tenant']])];
            $missing = $input; unset($missing['tenantId']); yield "$action missing tenant" => [$action, $missing];
            if (isset($input['revision'])) yield "$action coerced revision" => [$action, array_replace($input, ['revision' => '0'])];
            if (isset($input['payer'])) foreach ([null, [], 'payer', true] as $index => $payer) {
                yield "$action invalid payer $index" => [$action, array_replace($input, ['payer' => $payer])];
            }
        }
    }

    #[DataProvider('invalidCommands')]
    public function testMalformedCommandsFailBeforeAuthorizationOrPersistence(string $action, array $input): void
    {
        $user = $this->createStub(User::class); $user->method('isAdmin')->willReturn(true);
        $manager = $this->createMock(EntityManager::class); $manager->expects($this->never())->method('getPDO');
        $access = $this->createMock(Access::class); $access->expects($this->never())->method('assertTenant');
        $this->expectException(BadRequest::class);
        $this->invoke($this->controller($user, $manager, $access), $action, $input);
    }

    public function testBodySizeAndJsonShapeAreBounded(): void
    {
        $user = $this->createStub(User::class);
        $manager = $this->createMock(EntityManager::class); $manager->expects($this->never())->method('getPDO');
        $access = $this->createMock(Access::class); $access->expects($this->never())->method('assertTenant');
        $controller = $this->controller($user, $manager, $access);
        foreach (['null', '[]', '{', str_repeat(' ', 4097)] as $raw) {
            $request = $this->createStub(Request::class); $request->method('getBodyContents')->willReturn($raw);
            try { $controller->postActionCountry($request, $this->createStub(Response::class)); $this->fail('Malformed body accepted.'); }
            catch (BadRequest) { $this->addToAssertionCount(1); }
        }
    }

    public function testBillingRoutesRequireCrmAuthenticationAndNoProductionAdapterIsEnabled(): void
    {
        $routes = json_decode(file_get_contents('custom/Espo/Modules/FeatureCredits/Resources/routes.json'), true, 32, JSON_THROW_ON_ERROR);
        $routes = array_filter($routes, static fn ($route) => ($route['params']['controller'] ?? '') === 'CreditBilling');
        $this->assertCount(9, $routes);
        foreach ($routes as $route) $this->assertFalse($route['noAuth'] ?? false);
        $config = $this->createStub(Config::class);
        foreach ([null, 'asaas', 'stripe'] as $provider) $this->assertNull((new Providers($config, new Connections($config)))->capability($provider));
    }

    private function controller(User $user, EntityManager $manager, Access $access): CreditBilling
    {
        $clock = $this->createStub(Clock::class);
        $config = $this->createStub(Config::class); $config->method('get')->willReturn('checkout-test-key');
        $accounts = new Accounts($manager, $clock, new Catalog($manager, $clock), new Providers($config, new Connections($config)));
        $transport = $this->createMock(Transport::class); $transport->expects($this->never())->method('send');
        $creation = new Creation(new CreationStore($accounts, new Crypt($config), new Connections($config)), new Adapter($transport));
        return new CreditBilling($user, $access, $accounts, $creation);
    }

    private function invoke(CreditBilling $controller, string $action, array $input): void
    {
        $request = $this->createStub(Request::class);
        $request->method('getQueryParams')->willReturn($input);
        $request->method('getBodyContents')->willReturn(json_encode($input, JSON_THROW_ON_ERROR));
        $response = $this->createMock(Response::class);
        $response->expects($this->once())->method('setHeader')->with('Cache-Control', 'private, no-store');
        $controller->$action($request, $response);
    }
}

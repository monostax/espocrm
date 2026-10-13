<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureCredits;

use Espo\Core\Api\{Request, Response};
use Espo\Core\Exceptions\{BadRequest, Forbidden};
use Espo\Core\Utils\{Config, Crypt};
use Espo\Entities\User;
use Espo\Modules\FeatureAiUsage\Services\Access;
use Espo\Modules\FeatureCredits\Accounting\Clock;
use Espo\Modules\FeatureCredits\Billing\{Accounts, Catalog, Checkout, CreationStore, Providers};
use Espo\Modules\FeatureCredits\Billing\Asaas\{Connections, Transport};
use Espo\Modules\FeatureCredits\Controllers\CreditCheckout;
use Espo\Modules\Global\Tools\Tenant\{TenantResolver, UserTenantResolver};
use Espo\ORM\EntityManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CreditCheckoutTest extends TestCase
{
    public static function actions(): array { return ['getActionContext', 'getActionRead', 'postActionPayment']; }

    public static function denied(): iterable
    {
        foreach (self::actions() as $action) foreach (['member', 'api', 'portal', 'foreign-admin', 'missing-tenant'] as $kind) yield "$action $kind" => [$action, $kind];
    }

    private function controller(Access $access, EntityManager $manager): CreditCheckout
    {
        $config = $this->createStub(Config::class); $config->method('get')->willReturn('checkout-test-crypt-key');
        $clock = $this->createStub(Clock::class); $connections = new Connections($config);
        $accounts = new Accounts($manager, $clock, new Catalog($manager, $clock), new Providers($config, $connections));
        $transport = $this->createMock(Transport::class); $transport->expects($this->never())->method('send');
        return new CreditCheckout($access, new Checkout($accounts, new CreationStore($accounts, new Crypt($config), $connections), $connections, $transport));
    }

    private function request(string $action, array $extra = []): Request
    {
        $body = ['tenantId' => 'tenant'] + ($action === 'getActionContext' ? [] : ['obligationId' => 'obligation']) + $extra;
        $request = $this->createStub(Request::class);
        $request->method('getQueryParams')->willReturn($body); $request->method('getBodyContents')->willReturn(json_encode($body, JSON_THROW_ON_ERROR));
        return $request;
    }

    #[DataProvider('denied')]
    public function testFinancialAuthorizationPrecedesCheckoutReadsAndProviderLookup(string $action, string $kind): void
    {
        $user = $this->createStub(User::class);
        $user->method('isAdmin')->willReturn(in_array($kind, ['api', 'portal', 'missing-tenant'], true));
        $user->method('isApi')->willReturn($kind === 'api'); $user->method('isPortal')->willReturn($kind === 'portal');
        $user->method('getLinkMultipleIdList')->willReturn($kind === 'foreign-admin' ? [md5('tenant-admin')] : []);
        $memberships = $this->createStub(UserTenantResolver::class); $memberships->method('resolveTenantIds')->willReturn(['other']);
        $manager = $this->createMock(EntityManager::class); $manager->expects($this->never())->method('getPDO');
        $controller = $this->controller(new Access($user, $manager, $this->createStub(TenantResolver::class), $memberships), $manager);
        $response = $this->createMock(Response::class); $response->expects($this->once())->method('setHeader')->with('Cache-Control', 'private, no-store');
        $this->expectException(Forbidden::class); $controller->$action($this->request($action), $response);
    }

    public function testClientCannotSupplyProviderIdsUrlsOrPaymentStatus(): void
    {
        $manager = $this->createMock(EntityManager::class); $manager->expects($this->never())->method('getPDO');
        $access = $this->createMock(Access::class); $access->expects($this->never())->method('assertTenant');
        $controller = $this->controller($access, $manager);
        foreach (self::actions() as $action) foreach (['paymentId' => 'pay_foreign', 'invoiceUrl' => 'https://evil.test', 'paid' => true] as $key => $value) {
            try { $controller->$action($this->request($action, [$key => $value]), $this->createStub(Response::class)); $this->fail('Client payment authority accepted.'); }
            catch (BadRequest) { $this->addToAssertionCount(1); }
        }
        foreach (['null', '[]', '{', str_repeat(' ', 4097), '{"tenantId":[],"obligationId":"x"}'] as $raw) {
            $request = $this->createStub(Request::class); $request->method('getBodyContents')->willReturn($raw);
            try { $controller->postActionPayment($request, $this->createStub(Response::class)); $this->fail('Malformed lookup accepted.'); }
            catch (BadRequest) { $this->addToAssertionCount(1); }
        }
    }

    public function testHostedLinksAreExactHttpsEnvironmentBoundInvoiceUrls(): void
    {
        foreach (['sandbox' => 'sandbox.asaas.com', 'production' => 'www.asaas.com'] as $environment => $host) {
            $valid = 'https://' . $host . '/i/payment_123-abc';
            $this->assertSame($valid, Checkout::invoiceUrl($valid, $environment));
            foreach ([null, [], 'javascript:alert(1)', '//' . $host . '/i/p', str_replace('https:', 'http:', $valid),
                $valid . '?redirect=https://evil.test', $valid . '#fragment', $valid . "\n", $valid . '/',
                'https://' . $host . '.evil.test/i/p', 'https://' . $host . '@evil.test/i/p',
                'https://' . $host . ':443/i/p', 'https://' . $host . '/i/../p', 'https://' . $host . '/i/%2fother'] as $url) {
                $this->assertNull(Checkout::invoiceUrl($url, $environment));
            }
            $this->assertNull(Checkout::invoiceUrl($valid, $environment === 'sandbox' ? 'production' : 'sandbox'));
        }
        $routes = json_decode(file_get_contents('custom/Espo/Modules/FeatureCredits/Resources/routes.json'), true, 32, JSON_THROW_ON_ERROR);
        $routes = array_filter($routes, static fn ($r) => ($r['params']['controller'] ?? null) === 'CreditCheckout');
        $this->assertCount(3, $routes);
        foreach ($routes as $route) $this->assertFalse($route['noAuth'] ?? false);
    }
}

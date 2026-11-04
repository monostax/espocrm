<?php

declare(strict_types=1);

namespace tests\integration\Espo\Modules\FeatureSelfService;

use Espo\Core\Acl;
use Espo\Core\AclManager;
use Espo\Core\Api\Request;
use Espo\Core\Application;
use Espo\Core\Application\ApplicationParams;
use Espo\Entities\User;
use Espo\Modules\FeatureCredits\Accounting\Clock;
use Espo\Modules\FeatureCredits\Billing\Catalog;
use Espo\Modules\FeatureSelfService\Controllers\SelfServiceWorkspace;
use Espo\Modules\FeatureSelfService\Tools\Policy\SelfServicePolicy;
use Espo\Modules\FeatureSelfService\Tools\Provisioning\Provisioner;
use Espo\Modules\FeatureSelfService\Tools\Provisioning\ProvisioningRequest;
use Espo\ORM\EntityManager;
use PHPUnit\Framework\TestCase;

/**
 * The plans endpoint against the real self-service catalog file: prices,
 * packs labelled in conversations and the preselected recommendation.
 */
class PlanOffersTest extends TestCase
{
    private Application $app;
    private EntityManager $em;

    protected function setUp(): void
    {
        $database = getenv('SELF_SERVICE_TEST_DATABASE');
        $fake = (string) getenv('FAKE_CHATWOOT_URL');

        if (!$database || $fake === '') {
            self::markTestSkipped('Set SELF_SERVICE_TEST_DATABASE and FAKE_CHATWOOT_URL in an isolated CRM runtime.');
        }

        $this->app = new Application(new ApplicationParams(noErrorHandler: true));
        self::assertSame($database, $this->app->getContainer()->get('config')->get('database.dbname'));
        $this->app->setupSystemUser();
        $this->em = $this->app->getContainer()->get('entityManager');

        putenv(SelfServicePolicy::ENV . '=true');
        $this->app->getInjectableFactory()->create(SelfServicePolicy::class)->resume();

        if (!$this->em->getRDBRepository('ChatwootPlatform')->findOne()) {
            $this->em->createEntity('ChatwootPlatform', [
                'name' => 'Fake Chatwoot', 'backendUrl' => $fake, 'frontendUrl' => $fake, 'accessToken' => 'platform-token',
            ], ['skipHooks' => true]);
        }

        if (!$this->em->getPDO()->query("SELECT COUNT(*) FROM credit_billing_catalog WHERE version = 'self-service-2026-11-v1'")->fetchColumn()) {
            $definition = json_decode((string) file_get_contents(
                'custom/Espo/Modules/FeatureCredits/Resources/billing/self-service-2026-11.json'
            ), false, 32, JSON_THROW_ON_ERROR);
            $definition->effectiveFrom = gmdate('Y-m-d H:i:s', time() - 60);
            // Private offers need their real tenant, which this runtime doesn't have.
            $definition->offers = array_values(array_filter($definition->offers, fn ($offer) => !isset($offer->tenantId)));
            $kept = array_column($definition->offers, 'productId');
            $definition->products = array_values(array_filter($definition->products, fn ($product) => in_array($product->productId, $kept, true)));
            // Publishing only accepts future catalogs: publish from an hour ago.
            $clock = $this->createStub(Clock::class);
            $clock->method('now')->willReturn(gmdate('Y-m-d H:i:s', time() - 3600));
            $this->app->getInjectableFactory()->createWith(Catalog::class, ['clock' => $clock])->publish($definition, 'system');
        }
    }

    public function testThePlansPageGetsPricesPacksAndARecommendation(): void
    {
        $suffix = bin2hex(random_bytes(4));
        /** @var User $owner */
        $owner = $this->em->createEntity('User', [
            'firstName' => 'Owner', 'lastName' => $suffix, 'emailAddress' => "plans-{$suffix}@example.test",
            'type' => User::TYPE_REGULAR, 'isActive' => true,
        ]);
        $provisioner = $this->app->getInjectableFactory()->create(Provisioner::class);
        $record = $provisioner->start(new ProvisioningRequest("signup:plans-{$suffix}", (string) $owner->getId(), "Plans {$suffix}"));
        $done = $provisioner->run((string) $record->getId());
        $account = $this->em->getEntityById('ChatwootAccount', $done->get('chatwootAccountId'));
        $owner = $this->em->getEntityById('User', $owner->getId());

        $request = $this->createMock(Request::class);
        $request->method('getQueryParam')->willReturnCallback(
            fn (string $name) => $name === 'accountId' ? (string) $account->get('chatwootAccountId') : null
        );
        $controller = $this->app->getInjectableFactory()->createWith(SelfServiceWorkspace::class, [
            'user' => $owner, 'acl' => new Acl($this->app->getContainer()->get('aclManager'), $owner),
        ]);

        $offer = $controller->getActionPlans($request);

        self::assertSame(
            [['basic', '229.00', 1, 1], ['advanced', '289.00', 3, 3]],
            array_map(fn ($plan) => [$plan['key'], $plan['seatPrice'], $plan['whatsappIncluded'], $plan['instagramIncluded']], $offer->plans)
        );
        self::assertSame(
            [['2000', '997.00', 300], ['7000', '2497.00', 1075], ['15000', '4497.00', 2300]],
            array_map(fn ($pack) => [$pack['key'], $pack['price'], $pack['conversations']], $offer->packs)
        );
        self::assertSame('basic', $offer->recommendation['plan']);
        self::assertSame('2000', $offer->recommendation['pack']);
        self::assertGreaterThanOrEqual(1, $offer->recommendation['seats']);
        self::assertSame("plans-{$suffix}@example.test", $offer->payer['email']);
    }
}

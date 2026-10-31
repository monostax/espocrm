<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureCredits;

use Espo\Core\Exceptions\Conflict;
use Espo\Modules\FeatureCredits\Billing\{OfferTerms, ProviderCapability, Routing};
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BillingPolicyTest extends TestCase
{
    private function terms(array $changes = []): OfferTerms
    {
        return new OfferTerms(...array_replace([
            'offerId' => 'offer', 'version' => 'v1', 'productId' => 'plan', 'productVersion' => 'v1',
            'operation' => 'subscription', 'countries' => ['BR', 'US', 'CA'], 'currency' => 'BRL',
            'price' => '50', 'credits' => '100', 'interval' => 'month',
        ], $changes));
    }

    private function capability(array $changes = []): ProviderCapability
    {
        return new ProviderCapability(...array_replace([
            'provider' => 'asaas', 'integrationId' => 'integration', 'salesEnabled' => true,
            'currencies' => ['BRL'], 'operations' => ['purchase', 'subscription'],
        ], $changes));
    }

    public function testTermsCanonicalizeExactAmountsAndFreezeCommercialVersions(): void
    {
        $terms = $this->terms();
        $snapshot = $terms->jsonSerialize();
        $this->assertSame('50.00', $snapshot['price']);
        $this->assertSame('100.0000', $snapshot['credits']);
        $this->assertSame(['BR', 'CA', 'US'], $snapshot['countries']);
        $this->assertSame($snapshot, $this->terms([
            'countries' => ['US', 'BR', 'CA', 'BR'], 'price' => '50.00', 'credits' => '100.0000',
        ])->jsonSerialize());
        foreach ([
            ['offerId' => 'offer2'], ['version' => 'v2'], ['productId' => 'plan2'],
            ['productVersion' => 'v2'], ['countries' => ['BR']], ['currency' => 'USD'],
            ['price' => '51'], ['credits' => '101'], ['operation' => 'purchase', 'interval' => null],
        ] as $change) {
            $this->assertNotSame($terms->termsId, $this->terms($change)->termsId);
        }
        // Neither routing nor subsequent catalog values mutate a previously accepted value.
        Routing::checkout($terms, 'US', null, 'asaas', true, $this->capability());
        $this->assertSame($snapshot, $terms->jsonSerialize());
        $snapshot['countries'][] = 'MX';
        $this->assertSame(['BR', 'CA', 'US'], $terms->countries);
        $this->assertSame('0.0000', $this->terms(['credits' => '0'])->credits);
    }

    public static function invalidTerms(): iterable
    {
        foreach ([
            ['offerId' => ''], ['productId' => '../plan'], ['version' => 'V1'], ['productVersion' => ''],
            ['countries' => []], ['countries' => ['br']], ['countries' => [null]],
            ['countries' => ['home' => 'BR']], ['currency' => 'EUR'], ['currency' => 'brl'],
            ['price' => 50], ['price' => 50.0], ['price' => '0'], ['price' => '-1'],
            ['price' => '1e2'], ['price' => '1.001'], ['price' => ' 1'], ['price' => '01'],
            ['credits' => 100.0], ['credits' => '-1'], ['credits' => '0.00001'],
            ['operation' => 'purchase', 'credits' => '0', 'interval' => null],
            ['operation' => 'purchase'], ['interval' => null], ['interval' => 'year'],
            ['operation' => 'postpaid'], ['tenantId' => ''], ['tenantId' => '../tenant'], ['tenantId' => ' tenant'],
        ] as $i => $changes) yield (string) $i => [$changes];
    }

    public function testRestoreOriginalTermsWithoutCurrentCatalogAndRejectCorruption(): void
    {
        $terms = $this->terms();
        $snapshot = json_decode(json_encode($terms, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($terms->jsonSerialize(), OfferTerms::fromSnapshot($snapshot)->jsonSerialize());
        foreach ([
            ['price' => '51.00'], ['credits' => '200'], ['productVersion' => 'v2'],
            ['schema' => 'billing-offer-v2'], ['termsId' => str_repeat('0', 64)],
            ['currency' => null], ['interval' => []], ['countries' => 'BR'], ['price' => 50],
            ['paid' => true],
        ] as $change) {
            try {
                OfferTerms::fromSnapshot((object) array_replace((array) $snapshot, $change));
                $this->fail('Corrupt commercial snapshot accepted.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        unset($snapshot->price);
        $this->expectException(InvalidArgumentException::class);
        OfferTerms::fromSnapshot($snapshot);
    }

    public function testPrivateOfferScopeIsHashedAndRequiresTheExactTenant(): void
    {
        $public = $this->terms();
        $private = $this->terms(['tenantId' => 'tenant']);
        $this->assertArrayNotHasKey('tenantId', $public->jsonSerialize());
        $this->assertNotSame($public->termsId, $private->termsId);
        $this->assertNotSame($private->termsId, $this->terms(['tenantId' => 'other'])->termsId);
        $this->assertTrue($private->availableToTenant('tenant'));
        $this->assertFalse($private->availableToTenant('other'));
        $this->assertFalse($private->availableToTenant('Tenant'));
        $this->assertFalse($private->availableToTenant(null));
        $this->assertTrue($public->availableToTenant(null));
        $this->assertTrue(Routing::checkout($private, 'BR', null, null, false, $this->capability(), 'tenant')['eligible']);
        foreach ([null, 'other', 'Tenant'] as $tenantId) {
            $route = Routing::checkout($private, 'BR', null, null, false, $this->capability(), $tenantId);
            $this->assertFalse($route['eligible']);
            $this->assertSame('offer_unavailable', $route['blockedReason']);
            $this->assertNull($route['integrationId']);
        }
        $snapshot = json_decode(json_encode($private, JSON_THROW_ON_ERROR), false, 32, JSON_THROW_ON_ERROR);
        $this->assertSame($private->jsonSerialize(), OfferTerms::fromSnapshot($snapshot)->jsonSerialize());
        foreach (['other', null, '', [], false] as $tenantId) {
            $bad = clone $snapshot; $bad->tenantId = $tenantId;
            try { OfferTerms::fromSnapshot($bad); $this->fail('Private scope was changed without changing the original terms.'); }
            catch (InvalidArgumentException) { $this->addToAssertionCount(1); }
        }
        unset($snapshot->tenantId);
        $this->expectException(InvalidArgumentException::class);
        OfferTerms::fromSnapshot($snapshot);
    }

    public static function invalidCapabilities(): iterable
    {
        foreach ([
            ['provider' => 'unknown'], ['integrationId' => ''], ['currencies' => ['EUR']],
            ['currencies' => [null]], ['currencies' => [[]]], ['operations' => ['refund']],
            ['operations' => ['key' => 'purchase']], ['operations' => [true]],
        ] as $i => $change) yield (string) $i => [$change];
    }

    #[DataProvider('invalidCapabilities')]
    public function testInvalidCapabilitiesFailClosed(array $change): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->capability($change);
    }

    #[DataProvider('invalidTerms')]
    public function testRejectsInvalidOrImplicitCommercialTerms(array $changes): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->terms($changes);
    }

    public static function routes(): iterable
    {
        yield 'BR defaults Asaas' => ['BR', null, null, false, 'asaas', 'asaas', false];
        yield 'US defaults Stripe' => ['US', null, null, false, 'stripe', 'stripe', false];
        yield 'unsupported' => ['CA', null, null, false, null, null, false];
        yield 'missing' => [null, null, null, false, null, null, false];
        yield 'override' => ['US', 'asaas', null, false, 'asaas', 'asaas', false];
        yield 'override survives country edit' => ['BR', 'stripe', 'stripe', false, 'stripe', 'stripe', false];
        yield 'country changes without obligations' => ['US', null, 'asaas', false, 'stripe', 'stripe', false];
        yield 'country changes with obligations' => ['US', null, 'asaas', true, 'stripe', 'asaas', true];
        yield 'unsupported country with obligations' => ['CA', null, 'asaas', true, null, 'asaas', true];
        yield 'override cannot migrate obligations' => ['BR', 'stripe', 'asaas', true, 'stripe', 'asaas', true];
        yield 'obligations resolved' => ['CA', null, 'asaas', false, null, null, false];
    }

    #[DataProvider('routes')]
    public function testAssignmentPolicy(
        ?string $country, ?string $override, ?string $assigned, bool $obligations,
        ?string $requested, ?string $effective, bool $mismatch,
    ): void {
        $this->assertSame([
            'requestedProvider' => $requested, 'effectiveProvider' => $effective,
            'pinned' => $obligations, 'routeMismatch' => $mismatch,
        ], Routing::assignment($country, $override, $assigned, $obligations));
    }

    public function testUncertainObligationCannotBeTreatedAsUnassigned(): void
    {
        $this->expectException(Conflict::class);
        Routing::assignment('BR', null, null, true);
    }

    public function testOverrideChangesCannotEvadeObligations(): void
    {
        Routing::assertOverrideChange('BR', 'asaas', 'asaas', true);
        Routing::assertOverrideChange('US', 'stripe', 'asaas', false);
        foreach ([['BR', 'stripe'], ['US', null]] as [$country, $override]) {
            try {
                Routing::assertOverrideChange($country, $override, 'asaas', true);
                $this->fail('Provider reassignment bypassed unresolved obligations.');
            } catch (Conflict) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testCheckoutRequiresCurrentMarketAndExactProviderCapabilities(): void
    {
        $terms = $this->terms();
        $before = $terms->jsonSerialize();
        $ok = Routing::checkout($terms, 'BR', null, null, false, $this->capability());
        $this->assertTrue($ok['eligible']);
        $this->assertNull($ok['blockedReason']);
        $this->assertSame('integration', $ok['integrationId']);
        $this->assertSame($terms->termsId, $ok['termsId']);
        $cases = [
            [null, null, null, false, $this->capability(), 'billing_country_required'],
            ['MX', 'asaas', null, false, $this->capability(), 'market_ineligible'],
            ['CA', null, null, false, $this->capability(), 'billing_route_missing'],
            ['US', null, null, false, $this->capability(), 'billing_provider_unavailable'],
            ['US', null, null, false, $this->capability(['provider' => 'stripe', 'currencies' => ['BRL', 'USD']]), 'billing_provider_unavailable'],
            ['BR', null, null, false, null, 'billing_provider_unavailable'],
            ['BR', null, null, false, $this->capability(['salesEnabled' => false]), 'billing_provider_unavailable'],
            ['BR', null, null, false, $this->capability(['provider' => 'stripe']), 'billing_provider_unavailable'],
            ['BR', null, null, false, $this->capability(['currencies' => ['USD']]), 'billing_currency_unsupported'],
            ['BR', null, null, false, $this->capability(['operations' => ['purchase']]), 'billing_operation_unsupported'],
        ];
        foreach ($cases as [$country, $override, $assigned, $obligations, $capability, $reason]) {
            $result = Routing::checkout($terms, $country, $override, $assigned, $obligations, $capability);
            $this->assertFalse($result['eligible']);
            $this->assertSame($reason, $result['blockedReason']);
            $this->assertNull($result['integrationId']);
        }
        $this->assertSame($before, $terms->jsonSerialize());
    }

    public function testPinnedAssignmentCanSellOnlyCurrentlyEligibleCompatibleOffers(): void
    {
        $route = Routing::checkout($this->terms(), 'US', null, 'asaas', true, $this->capability());
        $this->assertTrue($route['eligible']);
        $this->assertTrue($route['routeMismatch']);
        $this->assertSame('asaas', $route['effectiveProvider']);
        $route = Routing::checkout($this->terms(['countries' => ['BR']]), 'US', null, 'asaas', true, $this->capability());
        $this->assertSame('market_ineligible', $route['blockedReason']);
        $route = Routing::checkout($this->terms(['currency' => 'USD']), 'US', null, 'asaas', true, $this->capability());
        $this->assertSame('billing_currency_unsupported', $route['blockedReason']);
        $route = Routing::checkout($this->terms(['operation' => 'purchase', 'interval' => null]), 'BR', null, null, false, $this->capability());
        $this->assertTrue($route['eligible']);
    }
}

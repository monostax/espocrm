<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureCredits;

use Espo\Modules\FeatureCredits\Billing\{CatalogDefinition, OfferTerms};
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/** Seat-priced plans: the catalog price is the base, accepted terms carry the total. */
final class BillingSeatTermsTest extends TestCase
{
    private static function catalog(array $seats = ['plan' => 'basic', 'unitPrice' => '229', 'min' => 1, 'max' => 50],
        string $price = '997'): CatalogDefinition
    {
        $input = BillingFixtures::definition();
        $input->offers[] = (object) ['offerId' => 'plan-basic-br', 'version' => 'v1', 'productId' => 'monthly', 'productVersion' => 'v1',
            'countries' => ['BR'], 'currency' => 'BRL', 'price' => $price, 'interval' => 'month', 'seats' => (object) $seats];

        return new CatalogDefinition($input);
    }

    public function testACatalogSeatOfferKeepsItsBasePrice(): void
    {
        $terms = self::catalog()->offers['plan-basic-br'];

        $this->assertSame('997.00', $terms->price);
        $this->assertSame(['plan' => 'basic', 'unitPrice' => '229.00', 'min' => 1, 'max' => 50], $terms->seats);
        $this->assertSame('billing-offer-v2', $terms->jsonSerialize()['schema']);
        $this->assertNull(self::catalog()->offers['monthly-br']->seats);
        $this->assertSame('billing-offer-v1', self::catalog()->offers['monthly-br']->jsonSerialize()['schema']);
    }

    public function testAcceptedSeatsPriceTheMonthlyTotalAndSurviveTheSnapshot(): void
    {
        $accepted = self::catalog()->offers['plan-basic-br']->withQuantity(5);

        $this->assertSame('2142.00', $accepted->price);
        $this->assertSame(5, $accepted->seats['quantity']);
        $this->assertSame('997.00', $accepted->seats['basePrice']);
        $this->assertNotSame(self::catalog()->offers['plan-basic-br']->termsId, $accepted->termsId);

        $restored = OfferTerms::fromSnapshot(json_decode(json_encode($accepted, JSON_THROW_ON_ERROR), false, 32, JSON_THROW_ON_ERROR));
        $this->assertEquals($accepted, $restored);
    }

    public function testTamperedSeatTermsAreRejected(): void
    {
        $snapshot = json_decode(json_encode(self::catalog()->offers['plan-basic-br']->withQuantity(5), JSON_THROW_ON_ERROR),
            false, 32, JSON_THROW_ON_ERROR);

        foreach ([
            fn ($s) => $s->seats->quantity = 1,
            fn ($s) => $s->price = '997.00',
            fn ($s) => $s->schema = 'billing-offer-v1',
            fn ($s) => $s->seats->unitPrice = '1.00',
        ] as $tamper) {
            $copy = json_decode(json_encode($snapshot, JSON_THROW_ON_ERROR), false, 32, JSON_THROW_ON_ERROR);
            $tamper($copy);
            try {
                OfferTerms::fromSnapshot($copy);
                $this->fail('Tampered seat terms were accepted.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testQuantitiesStayInRangeAndOnlyCatalogSeatOffersTakeOne(): void
    {
        $terms = self::catalog()->offers['plan-basic-br'];

        foreach ([0, 51] as $quantity) {
            try { $terms->withQuantity($quantity); $this->fail('Out-of-range seats were accepted.'); }
            catch (InvalidArgumentException) { $this->addToAssertionCount(1); }
        }
        foreach ([$terms->withQuantity(2), self::catalog()->offers['monthly-br']] as $other) {
            try { $other->withQuantity(3); $this->fail('Seats were applied twice or to a fixed offer.'); }
            catch (InvalidArgumentException) { $this->addToAssertionCount(1); }
        }
    }

    public function testSeatPricingNeedsAMonthlyPlanAndAValidRange(): void
    {
        foreach ([
            ['plan' => 'basic', 'unitPrice' => '0', 'min' => 1, 'max' => 50],
            ['plan' => 'basic', 'unitPrice' => '229', 'min' => 5, 'max' => 2],
            ['plan' => 'Basic', 'unitPrice' => '229', 'min' => 1, 'max' => 50],
            ['plan' => 'basic', 'unitPrice' => '229', 'min' => 1],
        ] as $seats) {
            try { self::catalog($seats); $this->fail('Invalid seat pricing was accepted.'); }
            catch (InvalidArgumentException) { $this->addToAssertionCount(1); }
        }

        $input = BillingFixtures::definition();
        $input->offers[] = (object) ['offerId' => 'pack-seats', 'version' => 'v1', 'productId' => 'pack', 'productVersion' => 'v1',
            'countries' => ['BR'], 'currency' => 'BRL', 'price' => '25', 'interval' => null,
            'seats' => (object) ['plan' => 'basic', 'unitPrice' => '229', 'min' => 1, 'max' => 50]];
        $this->expectException(InvalidArgumentException::class);
        new CatalogDefinition($input);
    }

    public function testIncludedCreditsComeWithEachSeatOnAFreeBase(): void
    {
        $terms = self::catalog(['plan' => 'monostax', 'unitPrice' => '249', 'min' => 1, 'max' => 1000, 'includedCredits' => '250'], '0')
            ->offers['plan-basic-br'];

        $this->assertSame('0.00', $terms->price, 'The one plan with no monthly credits on top costs only its attendants.');
        $this->assertSame('100.0000', $terms->credits);
        $this->assertSame('250.0000', $terms->seats['includedCredits']);

        $accepted = $terms->withQuantity(3);
        $this->assertSame('747.00', $accepted->price);
        $this->assertSame('850.0000', $accepted->credits, '100 base + 3 × 250 included.');
        $this->assertSame('100.0000', $accepted->seats['baseCredits']);
        $restored = OfferTerms::fromSnapshot(json_decode(json_encode($accepted, JSON_THROW_ON_ERROR), false, 32, JSON_THROW_ON_ERROR));
        $this->assertEquals($accepted, $restored);

        foreach ([
            fn ($s) => $s->credits = '1000.0000',
            fn ($s) => $s->seats->includedCredits = '500.0000',
            fn ($s) => $s->seats->baseCredits = '0.0000',
        ] as $tamper) {
            $copy = json_decode(json_encode($accepted, JSON_THROW_ON_ERROR), false, 32, JSON_THROW_ON_ERROR);
            $tamper($copy);
            try {
                OfferTerms::fromSnapshot($copy);
                $this->fail('Tampered included credits were accepted.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testOnlySeatOffersHaveAFreeBaseAndIncludedCreditsArePositive(): void
    {
        foreach ([
            ['plan' => 'monostax', 'unitPrice' => '249', 'min' => 1, 'max' => 1000, 'includedCredits' => '0'],
            ['plan' => 'monostax', 'unitPrice' => '249', 'min' => 1, 'max' => 1000, 'includedCredits' => 250],
        ] as $seats) {
            try { self::catalog($seats); $this->fail('Invalid included credits were accepted.'); }
            catch (InvalidArgumentException) { $this->addToAssertionCount(1); }
        }

        $input = BillingFixtures::definition();
        $input->offers[0]->price = '0';
        $this->expectException(InvalidArgumentException::class);
        new CatalogDefinition($input);
    }
}

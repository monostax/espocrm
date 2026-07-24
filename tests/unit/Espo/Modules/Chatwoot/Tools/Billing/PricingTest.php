<?php
/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

namespace tests\unit\Espo\Modules\Chatwoot\Tools\Billing;

use Espo\Modules\Chatwoot\Tools\Billing\PlanIncludedApplier;
use Espo\Modules\Chatwoot\Tools\Billing\Pricing;
use Espo\Modules\Chatwoot\Tools\Billing\RateCard;
use Espo\Modules\Chatwoot\Tools\Billing\TenantRateBook;
use PHPUnit\Framework\TestCase;

class PricingTest extends TestCase
{
    public function testPack199Empty(): void
    {
        $this->assertSame(
            ['turns' => 0, 'packs' => 0, 'amount' => 0.0],
            Pricing::pack199(0)
        );
    }

    /**
     * @dataProvider packCases
     */
    public function testPack199(int $turns, int $packs, float $amount): void
    {
        $result = Pricing::pack199($turns);

        $this->assertSame($turns, $result['turns']);
        $this->assertSame($packs, $result['packs']);
        $this->assertSame($amount, $result['amount']);
    }

    /**
     * @return list<array{int, int, float}>
     */
    public static function packCases(): array
    {
        return [
            [1, 1, 1.99],
            [4, 1, 1.99],
            [5, 2, 3.98],
            [8, 2, 3.98],
            [9, 3, 5.97],
            [12, 3, 5.97],
        ];
    }

    public function testPackWithCustomRateCard(): void
    {
        $rates = new RateCard(packUnitPrice: 2.50, packSize: 5);

        // 6 turns → ceil(6/5)=2 packs × 2.50 = 5.00
        $result = Pricing::pack199(6, $rates);

        $this->assertSame(2, $result['packs']);
        $this->assertSame(5.0, $result['amount']);
    }

    public function testExtra049Empty(): void
    {
        $result = Pricing::extra049(0, 0);

        $this->assertSame(0, $result['bases']);
        $this->assertSame(0.0, $result['amount']);
    }

    public function testExtra049BaseOnlyWithinFourCustomerTurns(): void
    {
        $result = Pricing::extra049(4, 0);

        $this->assertSame(1, $result['bases']);
        $this->assertSame(0, $result['turnOverages']);
        $this->assertSame(0, $result['kindExtras']);
        $this->assertSame(0.99, $result['amount']);
    }

    public function testExtra049CustomerOverage(): void
    {
        // 6 customer turns → 2 overages × 0.49 + base 0.99 = 1.97
        $result = Pricing::extra049(6, 0);

        $this->assertSame(1, $result['bases']);
        $this->assertSame(2, $result['turnOverages']);
        $this->assertSame(0, $result['kindExtras']);
        $this->assertSame(2, $result['extras']);
        $this->assertSame(1.97, $result['amount']);
    }

    public function testExtra049NonCustomerAlwaysExtra(): void
    {
        // base + 3 kind extras (follow-up/mention/…) = 0.99 + 1.47 = 2.46
        $result = Pricing::extra049(1, 3);

        $this->assertSame(1, $result['bases']);
        $this->assertSame(0, $result['turnOverages']);
        $this->assertSame(3, $result['kindExtras']);
        $this->assertSame(2.46, $result['amount']);
    }

    public function testExtra049Combined(): void
    {
        // 5 customer (1 overage) + 2 non-customer = 0.99 + 3×0.49 = 2.46
        $result = Pricing::extra049(5, 2);

        $this->assertSame(1, $result['turnOverages']);
        $this->assertSame(2, $result['kindExtras']);
        $this->assertSame(3, $result['extras']);
        $this->assertSame(2.46, $result['amount']);
    }

    public function testExtra049NonCustomerOnlyStillBase(): void
    {
        // Engaged by AI via follow-up alone still opens the day base.
        $result = Pricing::extra049(0, 2);

        $this->assertSame(1, $result['bases']);
        $this->assertSame(2, $result['kindExtras']);
        $this->assertSame(1.97, $result['amount']);
    }

    public function testExtraWithCustomRateCard(): void
    {
        $rates = new RateCard(
            extraBasePrice: 1.50,
            extraUnitPrice: 0.75,
            extraIncludedCustomerTurns: 2,
        );

        // 4 customer → 2 overages; 1 non-customer → 1 kind extra
        // amount = 1.50 + 3×0.75 = 3.75
        $result = Pricing::extra049(4, 1, $rates);

        $this->assertSame(2, $result['turnOverages']);
        $this->assertSame(1, $result['kindExtras']);
        $this->assertSame(3, $result['extras']);
        $this->assertSame(3.75, $result['amount']);
    }

    public function testRateCardFromNullableFallsBackOnEmpty(): void
    {
        $rates = RateCard::fromNullable(null, 0.0, -1.0, 0, null, '', null);

        $this->assertSame(RateCard::DEFAULT_PACK_UNIT_PRICE, $rates->packUnitPrice);
        $this->assertSame(RateCard::DEFAULT_EXTRA_BASE_PRICE, $rates->extraBasePrice);
        $this->assertSame(RateCard::DEFAULT_EXTRA_UNIT_PRICE, $rates->extraUnitPrice);
        $this->assertSame(RateCard::DEFAULT_PACK_SIZE, $rates->packSize);
        $this->assertSame(
            RateCard::DEFAULT_EXTRA_INCLUDED_CUSTOMER_TURNS,
            $rates->extraIncludedCustomerTurns
        );
        $this->assertSame(RateCard::DEFAULT_CURRENCY, $rates->currency);
    }

    public function testRateCardFromNullableKeepsPositiveOverrides(): void
    {
        $rates = RateCard::fromNullable(3.0, 1.2, 0.6, 10, 8, 'usd', 'BRL');

        $this->assertSame(3.0, $rates->packUnitPrice);
        $this->assertSame(1.2, $rates->extraBasePrice);
        $this->assertSame(0.6, $rates->extraUnitPrice);
        $this->assertSame(10, $rates->packSize);
        $this->assertSame(8, $rates->extraIncludedCustomerTurns);
        $this->assertSame('USD', $rates->currency);
    }

    public function testRateCardCurrencyFallsBackWhenInvalid(): void
    {
        $rates = RateCard::fromNullable(null, null, null, null, null, 'US', 'eur');

        $this->assertSame('EUR', $rates->currency);
    }

    public function testRateBookPicksPeriodByDay(): void
    {
        $old = new RateCard(packUnitPrice: 1.99, currency: 'BRL');
        $new = new RateCard(packUnitPrice: 0.99, packSize: 2, currency: 'BRL');
        $legacy = new RateCard(packUnitPrice: 9.99, currency: 'USD');

        $book = new TenantRateBook(
            [
                't1' => [
                    ['from' => '2026-07-01', 'to' => null, 'card' => $new],
                    ['from' => '2000-01-01', 'to' => '2026-06-30', 'card' => $old],
                ],
            ],
            ['t1' => $legacy],
            'BRL',
        );

        $this->assertSame(1.99, $book->get('t1', '2026-06-15')->packUnitPrice);
        $this->assertSame(0.99, $book->get('t1', '2026-07-01')->packUnitPrice);
        $this->assertSame(0.99, $book->get('t1', '2026-07-20')->packUnitPrice);
        // no day → open-ended current
        $this->assertSame(0.99, $book->get('t1')->packUnitPrice);
        // unknown tenant → defaults
        $this->assertSame(RateCard::DEFAULT_PACK_UNIT_PRICE, $book->get('missing')->packUnitPrice);
        // tenant with only legacy
        $legacyOnly = new TenantRateBook([], ['t2' => $legacy], 'BRL');
        $this->assertSame(9.99, $legacyOnly->get('t2', '2026-01-01')->packUnitPrice);
    }

    public function testRateCardFromNullablePlanIncluded(): void
    {
        $zero = RateCard::fromNullable(null, null, null, null, null, null, null, null);
        $this->assertSame(0, $zero->planIncludedUsage);

        $set = RateCard::fromNullable(null, null, null, null, null, null, null, 600);
        $this->assertSame(600, $set->planIncludedUsage);

        $neg = RateCard::fromNullable(null, null, null, null, null, null, null, -5);
        $this->assertSame(0, $neg->planIncludedUsage);
    }

    public function testPlanIncludedPackFifoWithinMonth(): void
    {
        $rates = new RateCard(
            packUnitPrice: 0.99,
            packSize: 2,
            planIncludedUsage: 3,
            currency: 'BRL',
        );

        // day1: 2 packs free; day2: 1 free + 1 billable; day3 next month resets
        $rows = [
            [
                'dayBucket' => '2026-07-02',
                'tenantId' => 't1',
                'rates' => $rates,
                'metrics' => ['packs' => 2, 'amountDeal' => 1.98, 'amount' => 1.98],
            ],
            [
                'dayBucket' => '2026-07-01',
                'tenantId' => 't1',
                'rates' => $rates,
                'metrics' => ['packs' => 2, 'amountDeal' => 1.98, 'amount' => 1.98],
            ],
            [
                'dayBucket' => '2026-08-01',
                'tenantId' => 't1',
                'rates' => $rates,
                'metrics' => ['packs' => 1, 'amountDeal' => 0.99, 'amount' => 0.99],
            ],
        ];

        $out = PlanIncludedApplier::apply($rows, 'pack199');

        // original order preserved
        $this->assertSame('2026-07-02', $out[0]['dayBucket']);
        $this->assertSame(1, $out[0]['metrics']['planIncludedUsed']);
        $this->assertSame(1, $out[0]['metrics']['billableUsage']);
        $this->assertSame(0.99, $out[0]['metrics']['amountDeal']);

        $this->assertSame('2026-07-01', $out[1]['dayBucket']);
        $this->assertSame(2, $out[1]['metrics']['planIncludedUsed']);
        $this->assertSame(0, $out[1]['metrics']['billableUsage']);
        $this->assertSame(0.0, $out[1]['metrics']['amountDeal']);

        $this->assertSame('2026-08-01', $out[2]['dayBucket']);
        $this->assertSame(1, $out[2]['metrics']['planIncludedUsed']);
        $this->assertSame(0, $out[2]['metrics']['billableUsage']);
        $this->assertSame(0.0, $out[2]['metrics']['amountDeal']);
    }

    public function testPlanIncludedExtraFifoAndZeroFranchise(): void
    {
        $withPlan = new RateCard(
            extraBasePrice: 0.99,
            planIncludedUsage: 1,
            currency: 'BRL',
        );
        $payg = new RateCard(extraBasePrice: 0.99, planIncludedUsage: 0, currency: 'BRL');

        $rows = [
            [
                'dayBucket' => '2026-07-01',
                'tenantId' => 'a',
                'rates' => $withPlan,
                'metrics' => [
                    'bases' => 1,
                    'amountDeal' => 1.97,
                    'amount' => 1.97,
                ],
            ],
            [
                'dayBucket' => '2026-07-02',
                'tenantId' => 'a',
                'rates' => $withPlan,
                'metrics' => [
                    'bases' => 1,
                    'amountDeal' => 0.99,
                    'amount' => 0.99,
                ],
            ],
            [
                'dayBucket' => '2026-07-01',
                'tenantId' => 'b',
                'rates' => $payg,
                'metrics' => [
                    'bases' => 1,
                    'amountDeal' => 0.99,
                    'amount' => 0.99,
                ],
            ],
        ];

        $out = PlanIncludedApplier::apply($rows, 'extra049');

        $this->assertSame(1, $out[0]['metrics']['planIncludedUsed']);
        $this->assertSame(0, $out[0]['metrics']['billableUsage']);
        $this->assertSame(0.0, $out[0]['metrics']['amountDeal']);

        $this->assertSame(0, $out[1]['metrics']['planIncludedUsed']);
        $this->assertSame(1, $out[1]['metrics']['billableUsage']);
        $this->assertSame(0.99, $out[1]['metrics']['amountDeal']);

        $this->assertSame(0, $out[2]['metrics']['planIncludedUsed']);
        $this->assertSame(1, $out[2]['metrics']['billableUsage']);
        $this->assertSame(0.99, $out[2]['metrics']['amountDeal']);
    }
}

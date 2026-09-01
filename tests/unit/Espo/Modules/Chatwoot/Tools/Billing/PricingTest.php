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
        $rates = RateCard::fromNullable(null, null, -1.0, 0, null, '', null);

        $this->assertSame(RateCard::DEFAULT_PACK_UNIT_PRICE, $rates->packUnitPrice);
        $this->assertSame(RateCard::DEFAULT_EXTRA_BASE_PRICE, $rates->extraBasePrice);
        $this->assertSame(RateCard::DEFAULT_EXTRA_UNIT_PRICE, $rates->extraUnitPrice);
        $this->assertSame(RateCard::DEFAULT_PACK_SIZE, $rates->packSize);
        $this->assertSame(
            RateCard::DEFAULT_EXTRA_INCLUDED_CUSTOMER_TURNS,
            $rates->extraIncludedCustomerTurns
        );
        $this->assertSame(RateCard::DEFAULT_CURRENCY, $rates->currency);
        $this->assertSame(RateCard::DEFAULT_CREDIT_UNIT_PRICE, $rates->creditUnitPrice);
        $this->assertSame(RateCard::DEFAULT_PLAN_INCLUDED_CREDITS, $rates->planIncludedCredits);
    }

    public function testRateCardFromNullableKeepsZeroAsFree(): void
    {
        // Explicit 0 = complimentary period (not "unset").
        $rates = RateCard::fromNullable(0.0, 0.0, 0.0, 4, 0, 'BRL', null);

        $this->assertSame(0.0, $rates->packUnitPrice);
        $this->assertSame(0.0, $rates->extraBasePrice);
        $this->assertSame(0.0, $rates->extraUnitPrice);
        $this->assertSame(4, $rates->packSize);
        $this->assertSame(0, $rates->extraIncludedCustomerTurns);
        $this->assertSame('BRL', $rates->currency);
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

        $out = PlanIncludedApplier::apply($rows, PlanIncludedApplier::MODEL_PACK);

        // original order preserved
        $this->assertSame('2026-07-02', $out[0]['dayBucket']);
        $this->assertSame(1, $out[0]['metrics']['packsIncluded']);
        $this->assertSame(1, $out[0]['metrics']['packsBillable']);
        $this->assertSame(0.99, $out[0]['metrics']['amountDeal']);

        $this->assertSame('2026-07-01', $out[1]['dayBucket']);
        $this->assertSame(2, $out[1]['metrics']['packsIncluded']);
        $this->assertSame(0, $out[1]['metrics']['packsBillable']);
        $this->assertSame(0.0, $out[1]['metrics']['amountDeal']);

        $this->assertSame('2026-08-01', $out[2]['dayBucket']);
        $this->assertSame(1, $out[2]['metrics']['packsIncluded']);
        $this->assertSame(0, $out[2]['metrics']['packsBillable']);
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

        $out = PlanIncludedApplier::apply($rows, PlanIncludedApplier::MODEL_EXTRA);

        $this->assertSame(1, $out[0]['metrics']['conversationsIncluded']);
        $this->assertSame(0, $out[0]['metrics']['conversationsBillable']);
        $this->assertSame(0.0, $out[0]['metrics']['amountDeal']);

        $this->assertSame(0, $out[1]['metrics']['conversationsIncluded']);
        $this->assertSame(1, $out[1]['metrics']['conversationsBillable']);
        $this->assertSame(0.99, $out[1]['metrics']['amountDeal']);

        $this->assertSame(0, $out[2]['metrics']['conversationsIncluded']);
        $this->assertSame(1, $out[2]['metrics']['conversationsBillable']);
        $this->assertSame(0.99, $out[2]['metrics']['amountDeal']);
    }

    public function testCreditEmpty(): void
    {
        $this->assertSame(
            [
                'credits' => 0,
                'replyCredits' => 0,
                'mentionCredits' => 0,
                'amount' => 0.0,
            ],
            Pricing::credit(0, 0)
        );
    }

    public function testCreditIsLinearAcrossKinds(): void
    {
        // 6 customer replies + 4 mention/follow-up runs = 10 credits × 0.49
        $result = Pricing::credit(6, 4);

        $this->assertSame(10, $result['credits']);
        $this->assertSame(6, $result['replyCredits']);
        $this->assertSame(4, $result['mentionCredits']);
        $this->assertSame(4.9, $result['amount']);
    }

    public function testCreditHasNoIncludedBundleInsideTheDay(): void
    {
        // Unlike extra049, there is no free pack per conversation-day:
        // the 4th reply already costs its own credit.
        $this->assertSame(1.96, Pricing::credit(4, 0)['amount']);
        $this->assertSame(0.49, Pricing::credit(1, 0)['amount']);
    }

    public function testCreditWithCustomUnitPrice(): void
    {
        $rates = new RateCard(creditUnitPrice: 0.25);

        $this->assertSame(1.5, Pricing::credit(4, 2, $rates)['amount']);
    }

    public function testRateCardFromNullableCreditOverrides(): void
    {
        $set = RateCard::fromNullable(
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            0.29,
            1000
        );

        $this->assertSame(0.29, $set->creditUnitPrice);
        $this->assertSame(1000, $set->planIncludedCredits);

        // Explicit 0 = complimentary credits; negative / null = platform default.
        $free = RateCard::fromNullable(null, null, null, null, null, null, null, null, 0.0, 0);
        $this->assertSame(0.0, $free->creditUnitPrice);
        $this->assertSame(0, $free->planIncludedCredits);

        $neg = RateCard::fromNullable(null, null, null, null, null, null, null, null, -1.0, -5);
        $this->assertSame(RateCard::DEFAULT_CREDIT_UNIT_PRICE, $neg->creditUnitPrice);
        $this->assertSame(RateCard::DEFAULT_PLAN_INCLUDED_CREDITS, $neg->planIncludedCredits);
    }

    public function testPlanIncludedCreditColumnsAreCreditSpecific(): void
    {
        $this->assertSame(
            [PlanIncludedApplier::COL_CREDITS_INCLUDED, PlanIncludedApplier::COL_CREDITS_BILLABLE],
            PlanIncludedApplier::outputColumns(PlanIncludedApplier::MODEL_CREDIT)
        );
        $this->assertSame(
            [
                PlanIncludedApplier::COL_CONVERSATIONS_INCLUDED,
                PlanIncludedApplier::COL_CONVERSATIONS_BILLABLE,
            ],
            PlanIncludedApplier::outputColumns(PlanIncludedApplier::MODEL_EXTRA)
        );
        $this->assertSame(
            [PlanIncludedApplier::COL_PACKS_INCLUDED, PlanIncludedApplier::COL_PACKS_BILLABLE],
            PlanIncludedApplier::outputColumns(PlanIncludedApplier::MODEL_PACK)
        );
    }

    public function testPlanIncludedCreditFranchiseFifoWithinMonth(): void
    {
        // 10 free credits/month at 0.49 each.
        $rates = new RateCard(
            currency: 'BRL',
            creditUnitPrice: 0.49,
            planIncludedCredits: 10,
        );

        $rows = [
            // Fed out of chronological order on purpose — the applier sorts.
            $this->creditRow('2026-07-02', 't1', $rates, 6),
            $this->creditRow('2026-07-01', 't1', $rates, 6),
            $this->creditRow('2026-08-01', 't1', $rates, 3),
        ];

        $out = PlanIncludedApplier::apply($rows, PlanIncludedApplier::MODEL_CREDIT);

        // Original row order is preserved.
        // 07-01 eats 6 of 10 free; 07-02 gets the last 4 free + 2 billed.
        $this->assertSame('2026-07-02', $out[0]['dayBucket']);
        $this->assertSame(4, $out[0]['metrics']['creditsIncluded']);
        $this->assertSame(2, $out[0]['metrics']['creditsBillable']);
        $this->assertSame(0.98, $out[0]['metrics']['amountDeal']);

        $this->assertSame('2026-07-01', $out[1]['dayBucket']);
        $this->assertSame(6, $out[1]['metrics']['creditsIncluded']);
        $this->assertSame(0, $out[1]['metrics']['creditsBillable']);
        $this->assertSame(0.0, $out[1]['metrics']['amountDeal']);

        // New calendar month resets the franchise.
        $this->assertSame('2026-08-01', $out[2]['dayBucket']);
        $this->assertSame(3, $out[2]['metrics']['creditsIncluded']);
        $this->assertSame(0, $out[2]['metrics']['creditsBillable']);
        $this->assertSame(0.0, $out[2]['metrics']['amountDeal']);

        // Credit model must not leak the pack/extra franchise column keys.
        $this->assertArrayNotHasKey('conversationsIncluded', $out[0]['metrics']);
        $this->assertArrayNotHasKey('packsIncluded', $out[0]['metrics']);
    }

    public function testPlanIncludedCreditPayAsYouGoBillsEveryCredit(): void
    {
        $payg = new RateCard(currency: 'BRL', creditUnitPrice: 0.49, planIncludedCredits: 0);

        $out = PlanIncludedApplier::apply(
            [$this->creditRow('2026-07-01', 't1', $payg, 5)],
            PlanIncludedApplier::MODEL_CREDIT
        );

        $this->assertSame(0, $out[0]['metrics']['creditsIncluded']);
        $this->assertSame(5, $out[0]['metrics']['creditsBillable']);
        $this->assertSame(2.45, $out[0]['metrics']['amountDeal']);
    }

    public function testPlanIncludedCreditIgnoresConversationFranchise(): void
    {
        // planIncludedUsage (conversation/pack franchise) must not be used
        // as a credit franchise — the two units are different magnitudes.
        $rates = new RateCard(
            planIncludedUsage: 500,
            currency: 'BRL',
            creditUnitPrice: 0.49,
            planIncludedCredits: 0,
        );

        $out = PlanIncludedApplier::apply(
            [$this->creditRow('2026-07-01', 't1', $rates, 2)],
            PlanIncludedApplier::MODEL_CREDIT
        );

        $this->assertSame(0, $out[0]['metrics']['creditsIncluded']);
        $this->assertSame(2, $out[0]['metrics']['creditsBillable']);
        $this->assertSame(0.98, $out[0]['metrics']['amountDeal']);
    }

    /**
     * @return array{
     *     dayBucket: string,
     *     tenantId: string,
     *     rates: RateCard,
     *     metrics: array<string, int|float>
     * }
     */
    private function creditRow(
        string $dayBucket,
        string $tenantId,
        RateCard $rates,
        int $credits
    ): array {
        $priced = Pricing::credit($credits, 0, $rates);

        return [
            'dayBucket' => $dayBucket,
            'tenantId' => $tenantId,
            'rates' => $rates,
            'metrics' => [
                'credits' => $priced['credits'],
                'replyCredits' => $priced['replyCredits'],
                'mentionCredits' => $priced['mentionCredits'],
                'amountDeal' => $priced['amount'],
                'amount' => $priced['amount'],
            ],
        ];
    }
}

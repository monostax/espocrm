<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureAiUsage;

use DateTimeImmutable;
use Espo\Modules\FeatureAiUsage\Services\Dataset;
use Espo\Modules\FeatureAiUsage\Services\Ledger;
use Espo\Modules\FeatureAiUsage\Services\Period;
use Espo\Modules\Chatwoot\Tools\Billing\RateCard;
use PHPUnit\Framework\TestCase;

class LedgerTest extends TestCase
{
    private function period(): Period
    {
        return Period::create('2026-08', 'UTC', new DateTimeImmutable('2026-09-27T12:00:00Z'));
    }

    private function agreement(string $model, ?RateCard $card = null, string $from = '2026-01-01', ?string $to = null): array
    {
        return ['id' => $from, 'from' => $from, 'to' => $to, 'model' => $model, 'card' => $card ?? new RateCard()];
    }

    private function engagement(string $id, string $day = '2026-08-01', string $kind = 'customer-message', string $scope = 'c'): array
    {
        return ['id' => $id, 'runAt' => $day . ' 12:00:00', 'kind' => $kind,
            'conversationId' => $kind === 'opportunity-mention' ? null : $scope,
            'opportunityId' => $kind === 'opportunity-mention' ? $scope : null];
    }

    public function testCreditsApplyOnceAcrossTheMonthAndFilteredConversationKeepsOverage(): void
    {
        $runs = [$this->engagement('1'), $this->engagement('2'), $this->engagement('3', '2026-08-20', 'opportunity-mention', 'o')];
        $card = new RateCard(planIncludedCredits: 2, creditUnitPrice: 0.5);
        $ledger = (new Ledger())->build($runs, $this->period(), [$this->agreement('credit', $card)], 't');
        $this->assertSame(3, $ledger['summary']['consumed']);
        $this->assertSame(2, $ledger['summary']['covered']);
        $this->assertSame(1, $ledger['summary']['overage']);
        $this->assertSame(0, $ledger['summary']['remaining']);
        $this->assertSame(0.5, $ledger['summary']['charges']['BRL']['amount']);
        $dataset = new Dataset();
        $filtered = $dataset->filter($ledger['runs'], ['from' => '2026-08-15']);
        $row = $dataset->breakdown($filtered, $ledger['groups'], 'opportunity', 0, 25)['list'][0];
        $this->assertSame(0, $row['billing']['covered']);
        $this->assertSame(0.5, $row['billing']['charges']['BRL']['amount']);
        $this->assertSame('included', Ledger::billingStatus($ledger['groups']['2026-08-01|conversation|c']));
        $this->assertSame('billed', Ledger::billingStatus($ledger['groups']['2026-08-20|opportunity|o']));
    }

    public function testPacksAreRoundedPerRecordDayNotAcrossTenantOrAction(): void
    {
        $runs = [$this->engagement('1'), $this->engagement('2', '2026-08-01', 'private-mention'), $this->engagement('3', '2026-08-02')];
        $ledger = (new Ledger())->build($runs, $this->period(), [$this->agreement('pack199', new RateCard(planIncludedUsage: 1))], 't');
        $this->assertSame(2, $ledger['summary']['consumed']);
        $this->assertSame(1.99, $ledger['summary']['charges']['BRL']['amount']);
        $dataset = new Dataset();
        $slice = $dataset->filter($ledger['runs'], ['kind' => 'private-mention']);
        $this->assertNull($dataset->breakdown($slice, $ledger['groups'], 'conversation', 0, 25)['list'][0]['billing']);
    }

    public function testIncludedExtraDayCoversAllExtrasAndNextDayChargesBaseAndExtras(): void
    {
        $runs = [];
        for ($day = 1; $day <= 2; $day++) {
            for ($i = 0; $i < 7; $i++) {
                $runs[] = $this->engagement("$day-$i", '2026-08-0' . $day, $i < 6 ? 'customer-message' : 'private-mention');
            }
        }
        $ledger = (new Ledger())->build($runs, $this->period(), [$this->agreement('extra049', new RateCard(planIncludedUsage: 1))], 't');
        $this->assertSame(0.0, $ledger['daily']['2026-08-01']['charges']['BRL']['amount']);
        $this->assertSame(2.46, $ledger['summary']['charges']['BRL']['amount']);
        $this->assertSame(0.99, $ledger['summary']['charges']['BRL']['base']);
        $this->assertSame(1.47, $ledger['summary']['charges']['BRL']['extras']);
    }

    public function testDatedPricesAndCurrenciesRemainSeparate(): void
    {
        $agreements = [
            $this->agreement('credit', new RateCard(creditUnitPrice: 0.25), '2026-01-01', '2026-08-15'),
            $this->agreement('credit', new RateCard(currency: 'USD', creditUnitPrice: 0.5), '2026-08-16'),
        ];
        $ledger = (new Ledger())->build([$this->engagement('1'), $this->engagement('2', '2026-08-20')], $this->period(), $agreements, 't');
        $this->assertSame('ready', $ledger['summary']['status']);
        $this->assertSame(0.25, $ledger['summary']['charges']['BRL']['amount']);
        $this->assertSame(0.5, $ledger['summary']['charges']['USD']['amount']);
    }

    public function testUnknownAndOverlappingContractsNeverDisplayZeroCharges(): void
    {
        foreach ([[], [$this->agreement('')], [$this->agreement('credit'), $this->agreement('pack199', from: '2026-08-01')]] as $agreements) {
            $ledger = (new Ledger())->build([$this->engagement('1')], $this->period(), $agreements, 't');
            $this->assertSame('configurationRequired', $ledger['summary']['status']);
            $this->assertNull($ledger['summary']['charges']);
            $this->assertNull($ledger['summary']['consumed']);
            $this->assertSame('unavailable', Ledger::billingStatus($ledger['groups']['2026-08-01|conversation|c']));
            $this->assertSame(1, $ledger['daily']['2026-08-01']['runs']);
        }
    }

    public function testMidMonthAllowanceChangeRequiresReview(): void
    {
        $rates = [
            $this->agreement('credit', new RateCard(planIncludedCredits: 10), '2026-01-01', '2026-08-15'),
            $this->agreement('credit', new RateCard(planIncludedCredits: 20), '2026-08-16'),
        ];
        $ledger = (new Ledger())->build([], $this->period(), $rates, 't');
        $this->assertSame('contractChanged', $ledger['summary']['reason']);
        $this->assertNull($ledger['summary']['allowance']);
    }

    public function testEmptyMonthAndPayAsYouGoRetainContractCurrency(): void
    {
        $ledger = (new Ledger())->build([], $this->period(), [$this->agreement('credit', new RateCard(currency: 'USD'))], 't');
        $this->assertSame(0, $ledger['summary']['consumed']);
        $this->assertSame(0, $ledger['summary']['allowance']);
        $this->assertSame(0.0, $ledger['summary']['charges']['USD']['amount']);
        $this->assertCount(31, $ledger['daily']);
    }

    public function testMonthBoundaryRenewsAllowanceAndFutureRatesDoNotRepricePast(): void
    {
        $rates = [
            $this->agreement('credit', new RateCard(planIncludedCredits: 1, creditUnitPrice: 0.5), '2026-01-01', '2026-08-31'),
            $this->agreement('pack199', new RateCard(planIncludedUsage: 10), '2026-09-01'),
        ];
        $august = (new Ledger())->build([$this->engagement('1'), $this->engagement('2')], $this->period(), $rates, 't');
        $this->assertSame(0.5, $august['summary']['charges']['BRL']['amount']);
        $this->assertSame('partiallyBilled', Ledger::billingStatus($august['groups']['2026-08-01|conversation|c']));
        $september = (new Ledger())->build([$this->engagement('3', '2026-09-02')], Period::create('2026-09', 'UTC', new DateTimeImmutable('2026-09-27')), $rates, 't');
        $this->assertSame(9, $september['summary']['remaining']);
    }

    public function testConversationAndOpportunityWithSameIdDoNotMerge(): void
    {
        $ledger = (new Ledger())->build([$this->engagement('1'), $this->engagement('2', kind: 'opportunity-mention')], $this->period(), [$this->agreement('pack199')], 't');
        $this->assertCount(2, $ledger['groups']);
        $this->assertSame(2, $ledger['summary']['consumed']);
    }

    public function testUnlinkedRunsRemainVisibleWithoutInventingBilling(): void
    {
        $ledger = (new Ledger())->build([$this->engagement('1', scope: '')], $this->period(), [$this->agreement('credit')], 't');
        $this->assertSame(0, $ledger['summary']['consumed']);
        $this->assertSame(1, (new Dataset())->stats($ledger['runs'])['unassignedRuns']);
        $this->assertSame('pending', Ledger::billingStatus(null));
    }

    public function testOnlyExplicitFailuresAreExcludedBeforePricingAndMonthlyAllowance(): void
    {
        $runs = [
            $this->engagement('failed-first') + ['runOutcome' => 'failed'],
            $this->engagement('completed', '2026-08-02') + ['runOutcome' => 'completed'],
            $this->engagement('cancelled', '2026-08-02') + ['runOutcome' => 'cancelled'],
            $this->engagement('superseded', '2026-08-02') + ['runOutcome' => 'superseded'],
            $this->engagement('legacy', '2026-08-03'),
            $this->engagement('failed-with-tools', '2026-08-02') + ['runOutcome' => 'failed', 'toolsUsed' => ['send_message']],
        ];
        $card = new RateCard(planIncludedCredits: 1, planIncludedUsage: 1, packSize: 2);
        foreach (['credit' => [4, 1.47], 'pack199' => [3, 3.98], 'extra049' => [2, 0.99]] as $model => [$units, $charge]) {
            $ledger = (new Ledger())->build($runs, $this->period(), [$this->agreement($model, $card)], 't');
            $this->assertSame($units, $ledger['summary']['consumed'], $model);
            $this->assertSame(1, $ledger['summary']['covered'], $model);
            $this->assertSame($charge, $ledger['summary']['charges']['BRL']['amount'], $model);
            $this->assertCount(6, $ledger['runs']);
            $this->assertCount(2, $ledger['groups']);
            $this->assertSame(0, $ledger['daily']['2026-08-01']['consumed']);
            $this->assertSame(1, $ledger['daily']['2026-08-01']['runs']);
            $this->assertSame(3, $ledger['groups']['2026-08-02|conversation|c']['runs']);
            $dataset = new Dataset();
            $this->assertSame(2, $dataset->stats($ledger['runs'])['failedRuns']);
            $this->assertSame(0, $dataset->stats($ledger['runs'])['unassignedRuns']);
            $row = $dataset->breakdown($ledger['runs'], $ledger['groups'], 'conversation', 0, 25)['list'][0];
            $this->assertSame(6, $row['runs']);
            $this->assertSame(2, $row['failedRuns']);
            $this->assertSame($units, $row['billing']['consumed']);
            $this->assertSame($charge, $row['billing']['charges']['BRL']['amount']);
        }
    }

    public function testZeroRateOverageIsNotReportedAsBilled(): void
    {
        $card = new RateCard(creditUnitPrice: 0, planIncludedCredits: 1);
        $ledger = (new Ledger())->build([$this->engagement('1'), $this->engagement('2')], $this->period(), [$this->agreement('credit', $card)], 't');
        $this->assertSame(1, $ledger['summary']['overage']);
        $this->assertSame('notBilled', Ledger::billingStatus($ledger['groups']['2026-08-01|conversation|c']));
    }

    public function testRepairingWaivedAttributionNeverConsumesAllowanceOrChangesCharges(): void
    {
        foreach (['credit', 'pack199', 'extra049'] as $model) {
            $runs = [$this->engagement('billable', '2026-08-02'),
                $this->engagement('waived', scope: '') + ['billingWaived' => true]];
            $card = new RateCard(planIncludedCredits: 1, planIncludedUsage: 1);
            $before = (new Ledger())->build($runs, $this->period(), [$this->agreement($model, $card)], 't');
            $runs[1]['conversationId'] = 'repaired';
            $after = (new Ledger())->build($runs, $this->period(), [$this->agreement($model, $card)], 't');
            $this->assertSame($before['summary'], $after['summary'], $model);
            $this->assertSame($before['groups'], $after['groups'], $model);
            $stats = (new Dataset())->stats($after['runs']);
            $this->assertSame(1, $stats['waivedRuns']);
            $this->assertSame(0, $stats['unassignedRuns']);
            $this->assertSame(0, $stats['pendingRuns']);
        }
    }
}

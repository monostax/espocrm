<?php

namespace tests\unit\Espo\Modules\Chatwoot\Tools\Billing;

use Espo\Modules\Chatwoot\Tools\Billing\ConversationDayGrain;
use Espo\Modules\Chatwoot\Tools\Billing\PlanIncludedApplier;
use Espo\Modules\Chatwoot\Tools\Billing\RateCard;
use PHPUnit\Framework\TestCase;

class OpportunityBillingTest extends TestCase
{
    public function testOpportunityMentionsAreIncludedInAllPricingModels(): void
    {
        $grains = ConversationDayGrain::fromGroupedCounts([
            ['scopeType' => 'opportunity', 'scopeId' => 'opp', 'dayBucket' => '2026-09-18',
                'tenantId' => 'tenant', 'kind' => 'opportunity-mention', 'cnt' => 25],
        ]);
        $this->assertCount(1, $grains);
        $grain = $grains[0];
        $this->assertNull($grain->conversationId);
        $this->assertSame('opp', $grain->opportunityId);
        $this->assertSame(0, $grain->customerMessageTurns);
        $this->assertSame(25, $grain->nonCustomerTurns);
        $this->assertSame(25, $grain->priceCredit()['mentionCredits']);
        $this->assertSame(25, $grain->priceCredit()['credits']);
        $this->assertSame(7, $grain->pricePack199()['packs']);
        $this->assertSame(1, $grain->priceExtra049()['bases']);
        $this->assertSame(25, $grain->priceExtra049()['kindExtras']);
        $this->assertSame(13.24, $grain->priceExtra049()['amount']);
    }

    public function testScopesTenantsAndDaysNeverMergeAndConversationKindsStillDo(): void
    {
        $base = ['scopeId' => 'same-id', 'dayBucket' => '2026-09-18', 'tenantId' => 'tenant', 'cnt' => 1];
        $grains = ConversationDayGrain::fromGroupedCounts([
            $base + ['scopeType' => 'conversation', 'kind' => 'customer-message'],
            $base + ['scopeType' => 'conversation', 'kind' => 'private-mention'],
            $base + ['scopeType' => 'opportunity', 'kind' => 'opportunity-mention'],
            array_replace($base, ['scopeType' => 'opportunity', 'tenantId' => 'other-tenant']),
            array_replace($base, ['scopeType' => 'opportunity', 'dayBucket' => '2026-09-19']),
            array_replace($base, ['scopeType' => 'opportunity', 'scopeId' => null]),
        ]);
        $this->assertCount(4, $grains);
        $this->assertSame(2, $grains[0]->totalTurns());
        $this->assertSame(1, $grains[0]->customerMessageTurns);
        $this->assertSame(1, $grains[0]->nonCustomerTurns);
        $this->assertSame(1, $grains[1]->totalTurns());
        $this->assertSame('other-tenant', $grains[2]->tenantId);
        $this->assertSame('2026-09-19', $grains[3]->dayBucket);
    }

    public function testTwentyFiveOpportunityEngagementsUseMonthlyIncludedCredits(): void
    {
        $rates = new RateCard(creditUnitPrice: 0.50, planIncludedCredits: 20);
        $rows = [];
        for ($i = 0; $i < 25; $i++) {
            $grain = new ConversationDayGrain(null, '2026-09-18', 'tenant', 0, 1, 'opp-' . $i);
            $priced = $grain->priceCredit($rates);
            $rows[] = [
                'dayBucket' => $grain->dayBucket, 'tenantId' => $grain->tenantId, 'rates' => $rates,
                'metrics' => $priced + ['amountDeal' => $priced['amount']],
            ];
        }
        $out = PlanIncludedApplier::apply($rows, PlanIncludedApplier::MODEL_CREDIT);
        $metrics = array_column($out, 'metrics');
        $this->assertSame(25, array_sum(array_column($metrics, 'credits')));
        $this->assertSame(20, array_sum(array_column($metrics, 'creditsIncluded')));
        $this->assertSame(5, array_sum(array_column($metrics, 'creditsBillable')));
        $this->assertSame(2.50, array_sum(array_column($metrics, 'amountDeal')));
    }
}

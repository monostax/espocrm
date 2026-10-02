<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureAiUsage;

use Espo\Modules\FeatureAiUsage\Services\Dataset;
use Espo\Modules\FeatureAiUsage\Services\Ledger;
use Espo\Modules\FeatureAiUsage\Services\Period;
use PHPUnit\Framework\TestCase;

class DatasetTest extends TestCase
{
    public function testConversationDaysUseLocalMidnightAndDeduplicateAcrossTriggersAndAgents(): void
    {
        $period = Period::create('2026-09', 'America/Sao_Paulo', new \DateTimeImmutable('2026-10-02T00:00:00Z'));
        $runs = [
            ['runAt' => '2026-09-02 02:59:59', 'conversationId' => 'a', 'kind' => 'customer-message', 'agentId' => 'one'],
            ['runAt' => '2026-09-02 02:59:59', 'conversationId' => 'a', 'kind' => 'private-mention', 'agentId' => 'two'],
            ['runAt' => '2026-09-02 03:00:00', 'conversationId' => 'a', 'kind' => 'followup-trigger'],
            ['runAt' => '2026-09-02 03:01:00', 'conversationId' => 'b', 'kind' => 'customer-message', 'runOutcome' => 'failed'],
            ['runAt' => '2026-09-02 03:02:00', 'opportunityId' => 'o', 'kind' => 'opportunity-mention'],
            ['runAt' => '2026-09-02 03:03:00', 'kind' => 'customer-message'],
        ];
        $ledger = (new Ledger())->build($runs, $period, [], 'tenant');
        $dataset = new Dataset();
        $stats = $dataset->stats($ledger['runs']);
        $this->assertSame(6, $stats['runs']);
        $this->assertSame(2, $stats['conversations']);
        $this->assertSame(3, $stats['conversationDays']);
        $filtered = $dataset->filter($ledger['runs'], ['from' => '2026-09-01', 'to' => '2026-09-01']);
        $this->assertSame(1, $dataset->stats($filtered)['conversationDays']);
        $this->assertSame(0, $dataset->stats([])['conversationDays']);
    }

    public function testOpportunityDaysCountLinkedRecordsOncePerDayIndependentlyOfConversations(): void
    {
        $runs = [
            ['day' => '2026-09-01', 'opportunityId' => 'a', 'kind' => 'opportunity-mention'],
            ['day' => '2026-09-01', 'opportunityId' => 'a', 'conversationId' => 'c', 'kind' => 'customer-message'],
            ['day' => '2026-09-02', 'opportunityId' => 'a'],
            ['day' => '2026-09-02', 'opportunityId' => 'b'],
            ['day' => '2026-09-02'],
        ];
        $dataset = new Dataset();
        $stats = $dataset->stats($runs);
        $this->assertSame(2, $stats['opportunities']);
        $this->assertSame(3, $stats['opportunityDays']);
        $this->assertSame(1, $stats['conversationDays']);
        $this->assertSame(1, $dataset->stats($dataset->filter($runs, ['to' => '2026-09-01']))['opportunityDays']);
        $this->assertSame(0, $dataset->stats([])['opportunityDays']);
    }

    public function testActionListsAndFlagsCountEachEngagementOncePerCategory(): void
    {
        $run = ['id' => '1', 'day' => '2026-08-01', 'groupKey' => null, 'kind' => 'private-mention',
            'toolsUsed' => ['transfer', 'transfer', 'search'], 'wasTransferred' => true, 'hadAppointment' => true];
        $this->assertSame(['transfer', 'search', 'appointments'], Dataset::actions($run));
        $rows = (new Dataset())->breakdown([$run], [], 'action', 0, 25)['list'];
        $this->assertCount(3, $rows);
        $this->assertSame([1, 1, 1], array_column($rows, 'runs'));
        $this->assertSame([null, null, null], array_column($rows, 'billing'));
    }

    public function testFilteringAndStablePaginationDoNotMultiplyEngagements(): void
    {
        $runs = [];
        for ($i = 0; $i < 60; $i++) {
            $runs[] = ['id' => (string) $i, 'day' => '2026-08-01', 'groupKey' => null, 'kind' => 'customer-message', 'agentId' => sprintf('agent-%02d', $i)];
        }
        $dataset = new Dataset();
        $page1 = $dataset->breakdown($runs, [], 'agent', 0, 25);
        $page2 = $dataset->breakdown($runs, [], 'agent', 25, 25);
        $this->assertSame(60, $page1['total']);
        $this->assertEmpty(array_intersect(array_column($page1['list'], 'key'), array_column($page2['list'], 'key')));
        $this->assertCount(1, $dataset->filter($runs, ['agentId' => 'agent-25']));
    }

    public function testActiveDaysIncludeTheLastDayAndDoNotCountRepeatedRunsTwice(): void
    {
        $runs = [];
        foreach (['01', '31', '31'] as $day) {
            $runs[] = ['day' => '2026-08-' . $day, 'agentId' => 'a', 'groupKey' => null];
        }
        $row = (new Dataset())->breakdown($runs, [], 'agent', 0, 25)['list'][0];
        $this->assertSame(3, $row['runs']);
        $this->assertSame(2, $row['days']);
    }

    public function testFailureCountsRespectFiltersAndOverlappingActions(): void
    {
        $base = ['day' => '2026-08-01', 'groupKey' => null, 'kind' => 'customer-message', 'toolsUsed' => ['search', 'done']];
        $runs = [
            ['id' => 'failed', 'runOutcome' => 'failed'] + $base,
            ['id' => 'completed', 'runOutcome' => 'completed'] + $base,
            ['id' => 'legacy', 'kind' => 'private-mention'] + $base,
        ];
        $dataset = new Dataset();
        $filtered = $dataset->filter($runs, ['kind' => 'customer-message']);
        $this->assertSame(1, $dataset->stats($filtered)['failedRuns']);
        foreach (['kind', 'action'] as $dimension) {
            $rows = $dataset->breakdown($filtered, [], $dimension, 0, 25)['list'];
            foreach ($rows as $row) {
                $this->assertSame(2, $row['runs']);
                $this->assertSame(1, $row['failedRuns']);
                $this->assertSame(100.0, $row['share']);
            }
        }
        $this->assertSame(0, $dataset->stats($dataset->filter($runs, ['kind' => 'private-mention']))['failedRuns']);
    }

    public function testFailureOnlyConversationHasKnownZeroBillingWithoutARateOrBillingGroup(): void
    {
        $runs = [['id' => 'failed', 'runOutcome' => 'failed', 'day' => '2026-08-01', 'conversationId' => 'c', 'groupKey' => '2026-08-01|conversation|c']];
        $row = (new Dataset())->breakdown($runs, [], 'conversation', 0, 25)['list'][0];
        $this->assertSame(1, $row['runs']);
        $this->assertSame(1, $row['failedRuns']);
        $this->assertSame(0, $row['billing']['consumed']);
        $this->assertSame(0, $row['billing']['covered']);
        $this->assertSame([], $row['billing']['charges']);
    }

    public function testWaivedAndFailedRunsAreNotReportedAsPendingEvenWithoutAttribution(): void
    {
        $base = ['day' => '2026-08-01', 'groupKey' => null, 'conversationId' => 'c'];
        $runs = [['billingWaived' => true] + $base, ['runOutcome' => 'failed'] + $base, $base];
        $stats = (new Dataset())->stats($runs);
        $this->assertSame(3, $stats['unassignedRuns']);
        $this->assertSame(1, $stats['waivedRuns']);
        $this->assertSame(1, $stats['pendingRuns']);
        $row = (new Dataset())->breakdown([$runs[0]], [], 'conversation', 0, 25)['list'][0];
        $this->assertSame(0, $row['billing']['consumed']);
    }
}

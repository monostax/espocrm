<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureAiUsage;

use Espo\Modules\FeatureAiUsage\Services\Dataset;
use PHPUnit\Framework\TestCase;

class DatasetTest extends TestCase
{
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

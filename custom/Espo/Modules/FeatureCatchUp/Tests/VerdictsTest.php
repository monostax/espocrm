<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCatchUp\Tests;

use Espo\Modules\FeatureCatchUp\Services\Verdicts;
use Espo\ORM\EntityManager;
use PHPUnit\Framework\TestCase;

class VerdictsTest extends TestCase
{
    /** @param list<array{string, string, int}> $rows subject id, verdict, resolved */
    private function verdicts(array $rows, ?string &$sql = null): Verdicts
    {
        $statement = $this->createMock(\PDOStatement::class);
        $statement->method('execute')->willReturn(true);
        $statement->method('fetchAll')->willReturn($rows);
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('prepare')->willReturnCallback(function (string $query) use ($statement, &$sql) {
            $sql = $query;
            return $statement;
        });
        $em = $this->createMock(EntityManager::class);
        $em->method('getPDO')->willReturn($pdo);
        return new Verdicts($em);
    }

    public function testACardSomeoneActedOnStaysOutOfTheDeckUntilTheRevisionMoves(): void
    {
        $judged = $this->verdicts([
            ['waiting', 'customer_waiting', 0],
            ['resolved', 'follow_up_due', 1],
            // Another reader's analysis at the same revision, stored after the card was acted on.
            ['resolvedThenJudged', 'customer_waiting', 1],
            ['resolvedThenJudged', 'promise_due', 0],
            ['quietThenCard', 'none', 1],
            ['quietThenCard', 'customer_waiting', 0],
            ['quiet', 'scheduled', 0],
        ], $sql)->judged('ChatwootConversation', [
            'waiting' => 'r1', 'resolved' => 'r2', 'resolvedThenJudged' => 'r3', 'quietThenCard' => 'r4', 'quiet' => 'r5', 'unjudged' => 'r6',
        ]);

        self::assertStringContainsString('resolved_at IS NOT NULL', $sql);
        self::assertSame([
            'waiting' => 'customer_waiting', 'resolved' => 'none', 'resolvedThenJudged' => 'none',
            'quietThenCard' => 'customer_waiting', 'quiet' => 'scheduled',
        ], $judged);
    }
}

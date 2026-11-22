<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureCredits;

use Espo\Modules\FeatureCredits\Jobs\CreditQueueRecovery;
use PDO;
use PDOException;
use PDOStatement;
use PHPUnit\Framework\TestCase;

/** The cron tick's requeue deadlocked with the job runner's own updates a few times a day (2026-11-21/22). */
final class CreditQueueRecoveryTest extends TestCase
{
    public function testADeadlockedRequeueRunsAgainInAutocommit(): void
    {
        $failures = [self::error('40001', 1213)];
        $this->assertSame(2, $this->requeue(false, $failures));
    }

    public function testARecurringDeadlockGivesUpAfterThreeAttempts(): void
    {
        $failures = array_fill(0, 5, self::error('40001', 1213));
        try {
            $this->requeue(false, $failures);
            $this->fail('Expected the deadlock after the last attempt.');
        } catch (PDOException $e) {
            $this->assertSame('40001', $e->errorInfo[0]);
        }
        $this->assertCount(2, $failures);
    }

    public function testOtherErrorsAndCallerTransactionsAreNotRetried(): void
    {
        foreach ([[false, self::error('23000', 1062)], [true, self::error('40001', 1213)]] as [$inTransaction, $error]) {
            $failures = [$error];
            try {
                $this->requeue($inTransaction, $failures);
                $this->fail('Expected the error to propagate.');
            } catch (PDOException $e) {
                $this->assertSame($error, $e);
            }
            $this->assertSame([], $failures);
        }
    }

    /** @param list<PDOException> $failures thrown by successive executions, then success */
    private function requeue(bool $inTransaction, array &$failures): int
    {
        $executions = 0;
        $statement = $this->createMock(PDOStatement::class);
        $statement->method('execute')->willReturnCallback(function () use (&$executions, &$failures): bool {
            $executions++;
            if ($failures) throw array_shift($failures);
            return true;
        });
        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturn($statement);
        $pdo->method('inTransaction')->willReturn($inTransaction);
        CreditQueueRecovery::requeueUnstarted($pdo, '2026-11-22 12:00:00');
        return $executions;
    }

    private static function error(string $state, int $code): PDOException
    {
        $e = new PDOException("SQLSTATE[$state]: database error $code");
        $e->errorInfo = [$state, $code, 'database error'];
        return $e;
    }
}

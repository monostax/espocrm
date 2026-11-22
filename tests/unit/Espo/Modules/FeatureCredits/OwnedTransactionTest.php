<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureCredits;

use Espo\Modules\FeatureCredits\Accounting\OwnedTransaction;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

final class OwnedTransactionTest extends TestCase
{
    private bool $active = false;
    private array $calls = ['begin' => 0, 'commit' => 0, 'rollBack' => 0];

    public function testADeadlockRunsTheCallbackAgainInAFreshTransaction(): void
    {
        $attempts = 0;
        $result = OwnedTransaction::run($this->pdo(), function () use (&$attempts): string {
            if (++$attempts === 1) throw self::error('40001', 1213);
            return 'saved';
        });
        $this->assertSame('saved', $result);
        $this->assertSame(2, $attempts);
        $this->assertSame(['begin' => 2, 'commit' => 1, 'rollBack' => 1], $this->calls);
        $this->assertFalse($this->active);
    }

    public function testOtherFailuresRollBackOnceAndPropagate(): void
    {
        $failure = self::error('23000', 1062);
        $attempts = 0;
        try {
            OwnedTransaction::run($this->pdo(), function () use (&$attempts, $failure): never {
                $attempts++;
                throw $failure;
            });
            $this->fail('Expected the duplicate key error.');
        } catch (PDOException $e) {
            $this->assertSame($failure, $e);
        }
        $this->assertSame(1, $attempts);
        $this->assertSame(['begin' => 1, 'commit' => 0, 'rollBack' => 1], $this->calls);
    }

    public function testARecurringDeadlockGivesUpAfterTheLastAttempt(): void
    {
        $attempts = 0;
        try {
            OwnedTransaction::run($this->pdo(), function () use (&$attempts): never {
                $attempts++;
                throw self::error('40P01', 7);
            });
            $this->fail('Expected the deadlock after the last attempt.');
        } catch (PDOException $e) {
            $this->assertSame('40P01', $e->errorInfo[0]);
        }
        $this->assertSame(OwnedTransaction::ATTEMPTS, $attempts);
        $this->assertSame(['begin' => 4, 'commit' => 0, 'rollBack' => 4], $this->calls);
    }

    private function pdo(): PDO
    {
        $pdo = $this->createMock(PDO::class);
        $pdo->method('inTransaction')->willReturnCallback(fn (): bool => $this->active);
        $pdo->method('beginTransaction')->willReturnCallback(function (): bool {
            $this->calls['begin']++; return $this->active = true;
        });
        $pdo->method('commit')->willReturnCallback(function (): bool {
            $this->calls['commit']++; $this->active = false; return true;
        });
        $pdo->method('rollBack')->willReturnCallback(function (): bool {
            $this->calls['rollBack']++; $this->active = false; return true;
        });
        return $pdo;
    }

    private static function error(string $state, int $code): PDOException
    {
        $e = new PDOException("SQLSTATE[$state]: database error $code");
        $e->errorInfo = [$state, $code, 'database error'];
        return $e;
    }
}

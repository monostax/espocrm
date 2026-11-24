<?php
/************************************************************************
 * This file is part of EspoCRM.
 *
 * EspoCRM – Open Source CRM application.
 * Copyright (C) 2014-2026 EspoCRM, Inc.
 * Website: https://www.espocrm.com
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 *
 * The interactive user interfaces in modified source and object code versions
 * of this program must display Appropriate Legal Notices, as required under
 * Section 5 of the GNU Affero General Public License version 3.
 *
 * In accordance with Section 7(b) of the GNU Affero General Public License version 3,
 * these Appropriate Legal Notices must retain the display of the "EspoCRM" word.
 ************************************************************************/

namespace tests\unit\Espo\ORM;

use Espo\ORM\Exceptions\DeadlockException;
use Espo\ORM\QueryComposer\MysqlQueryComposer;
use Espo\ORM\TransactionManager;
use PDO;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class TransactionManagerTest extends TestCase
{
    private $pdo;
    private $manager;
    private bool $transactionActive = true;

    protected function setUp() : void
    {
        $this->pdo = $this->createMock(PDO::class);
        $this->pdo->method('inTransaction')->willReturnCallback(fn () => $this->transactionActive);
        $composer = $this->createMock(MysqlQueryComposer::class);

        $composer
            ->expects($this->any())
            ->method('composeCreateSavepoint')
            ->willReturnCallback(
                function ($name) {
                    return 'SAVEPOINT ' . $name;
                }
            );

        $composer
            ->expects($this->any())
            ->method('composeReleaseSavepoint')
            ->willReturnCallback(
                function ($name) {
                    return 'RELEASE SAVEPOINT ' . $name;
                }
            );

        $composer
            ->expects($this->any())
            ->method('composeRollbackToSavepoint')
            ->willReturnCallback(
                function ($name) {
                    return 'ROLLBACK TO SAVEPOINT ' . $name;
                }
            );

        $this->manager = new TransactionManager($this->pdo, $composer);
    }

    public function testStartOnce()
    {
        $this->pdo
            ->expects($this->once())
            ->method('beginTransaction');

        $this->manager->start();
    }

    public function testNested()
    {
        $this->pdo
            ->expects($this->exactly(1))
            ->method('beginTransaction');

        $invokedCount = $this->exactly(4);

        $this->pdo
            ->expects($invokedCount)
            ->method('exec')
            ->willReturnCallback(function ($sql) use ($invokedCount) {
                if ($invokedCount->numberOfInvocations() === 1) {
                    $this->assertEquals('SAVEPOINT POINT_1', $sql);
                }

                if ($invokedCount->numberOfInvocations() === 2) {
                    $this->assertEquals('SAVEPOINT POINT_2', $sql);
                }

                if ($invokedCount->numberOfInvocations() === 3) {
                    $this->assertEquals('RELEASE SAVEPOINT POINT_2', $sql);
                }

                if ($invokedCount->numberOfInvocations() === 4) {
                    $this->assertEquals('ROLLBACK TO SAVEPOINT POINT_1', $sql);
                }

                return 1;
            });

       $this->pdo
            ->expects($this->exactly(1))
            ->method('commit');

        $this->manager->start();
        $this->manager->start();
        $this->manager->start();

        $this->manager->commit();
        $this->manager->rollback();
        $this->manager->commit();
    }

    public function testNestedRollback()
    {
        $invokedCount = $this->exactly(2);

        $this->pdo
            ->expects($invokedCount)
            ->method('exec')
            ->willReturnCallback(function ($sql) use ($invokedCount) {
                if ($invokedCount->numberOfInvocations() === 1) {
                    $this->assertEquals('SAVEPOINT POINT_1', $sql);
                }

                if ($invokedCount->numberOfInvocations() === 2) {
                    $this->assertEquals('ROLLBACK TO SAVEPOINT POINT_1', $sql);
                }

                return 1;
            });

        $this->pdo
            ->expects($this->once())
            ->method('rollBack');

        $this->manager->start();
        $this->manager->start();

        $this->manager->rollback();
        $this->manager->rollback();
    }

    public function testLevel()
    {
        $this->assertEquals(0, $this->manager->getLevel());

        $this->assertFalse($this->manager->isStarted());

        $this->manager->start();

        $this->assertEquals(1, $this->manager->getLevel());

        $this->assertTrue($this->manager->isStarted());

        $this->manager->start();

        $this->assertEquals(2, $this->manager->getLevel());

        $this->manager->commit();

        $this->assertEquals(1, $this->manager->getLevel());

        $this->manager->rollback();

        $this->assertEquals(0, $this->manager->getLevel());

        $this->assertFalse($this->manager->isStarted());
    }

    public function testError1()
    {
        $this->expectException(RuntimeException::class);

        $this->manager->commit();
    }

    public function testError2()
    {
        $this->expectException(RuntimeException::class);

        $this->manager->start();

        $this->assertTrue($this->manager->isStarted());

        $this->manager->commit();
        $this->manager->rollback();
    }

    public function testRunOnce()
    {
        $this->pdo
            ->expects($this->once())
            ->method('beginTransaction');

        $this->pdo
            ->expects($this->once())
            ->method('commit');

        $this->manager->run(
            function () {}
        );
    }

    public function testRunNested()
    {
        $this->pdo
            ->expects($this->once())
            ->method('beginTransaction');

        $invokedCount = $this->exactly(2);

        $this->pdo
            ->expects($invokedCount)
            ->method('exec')
            ->willReturnCallback(function ($sql) use ($invokedCount) {
                if ($invokedCount->numberOfInvocations() === 1) {
                    $this->assertEquals('SAVEPOINT POINT_1', $sql);
                }

                if ($invokedCount->numberOfInvocations() === 2) {
                    $this->assertEquals('RELEASE SAVEPOINT POINT_1', $sql);
                }

                return 1;
            });

        $this->pdo
            ->expects($this->once())
            ->method('commit');

        $this->manager->run(
            function () {
                $this->manager->run(
                    function () {}
                );
            }
        );
    }

    public function testRunException()
    {
        $this->pdo
            ->expects($this->once())
            ->method('beginTransaction');

        $this->pdo
            ->expects($this->once())
            ->method('rollback');

        try {
            $this->manager->run(
                function () {
                    throw new RuntimeException();
                }
            );
        } catch (RuntimeException) {}
    }

    /**
     * Upstream 10.1 test, adapted to this fixture: `inTransaction()` is true after BEGIN
     * and false once InnoDB has rolled the transaction back on the deadlock.
     */
    public function testDeadlock(): void
    {
        $expectedCount = 4;

        $this->transactionActive = false;

        $this->pdo
            ->expects($this->exactly($expectedCount))
            ->method('beginTransaction')
            ->willReturnCallback(function () {
                $this->transactionActive = true;

                return true;
            });

        $this->pdo
            ->expects($this->never())
            ->method('rollBack');

        $counter = 0;
        $thrown = false;

        try {
            $this->manager->run(function () use (&$counter) {
                $counter ++;

                $this->transactionActive = false;

                throw new DeadlockException(previous: new PDOException());
            });
        } catch (DeadlockException) {
            $thrown = true;
        }

        $this->assertTrue($thrown);
        $this->assertEquals($expectedCount, $counter);
        $this->assertSame(0, $this->manager->getLevel());
    }

    /**
     * Upstream 10.1 test, adapted like testDeadlock(). Only the outermost run() retries.
     */
    public function testNestedDeadlock(): void
    {
        $expectedCount = 4;

        $this->transactionActive = false;

        $this->pdo
            ->expects($this->exactly($expectedCount))
            ->method('beginTransaction')
            ->willReturnCallback(function () {
                $this->transactionActive = true;

                return true;
            });

        $this->pdo
            ->expects($this->never())
            ->method('rollBack');

        $counter = 0;
        $outermostCounter = 0;
        $thrown = false;

        try {
            $this->manager->run(function () use (&$counter, &$outermostCounter) {
                $outermostCounter ++;

                $this->manager->run(function () use (&$counter) {
                    $counter ++;

                    $this->transactionActive = false;

                    throw new DeadlockException(previous: new PDOException());
                });
            });

        } catch (DeadlockException) {
            $thrown = true;
        }

        $this->assertTrue($thrown);
        $this->assertEquals($expectedCount, $counter);
        $this->assertEquals($expectedCount, $outermostCounter);
        $this->assertSame(0, $this->manager->getLevel());
    }

    public function testFailedCommitPreservesOriginalExceptionAndRollsBack(): void
    {
        $failure = new \PDOException('Commit failed');
        $this->pdo->expects($this->once())->method('commit')->willThrowException($failure);
        $this->pdo->expects($this->once())->method('rollBack');

        try {
            $this->manager->run(function () {});
            $this->fail('Expected commit failure.');
        } catch (\PDOException $e) {
            $this->assertSame($failure, $e);
        }

        $this->assertSame(0, $this->manager->getLevel());
    }

    public function testDatabaseRollbackUnwindsNestedScopesWithoutMaskingFailure(): void
    {
        $failure = new \PDOException('Deadlock');
        $this->pdo->expects($this->never())->method('rollBack');
        $this->pdo->expects($this->never())->method('commit');
        $this->pdo->expects($this->once())->method('exec')->with('SAVEPOINT POINT_1');

        try {
            $this->manager->run(function () use ($failure) {
                $this->manager->run(function () use ($failure) {
                    $this->transactionActive = false;
                    throw $failure;
                });
            });
            $this->fail('Expected deadlock.');
        } catch (\PDOException $e) {
            $this->assertSame($failure, $e);
        }

        $this->assertSame(0, $this->manager->getLevel());
    }

    public function testSwallowedNestedDeadlockCannotCommitOuterScope(): void
    {
        $this->pdo->expects($this->never())->method('commit');
        $this->pdo->expects($this->never())->method('rollBack');

        try {
            $this->manager->run(function () {
                try {
                    $this->manager->run(function () {
                        $this->transactionActive = false;
                        throw new \PDOException('Deadlock');
                    });
                } catch (\PDOException) {}

                $this->assertSame(1, $this->manager->getLevel());
            });
            $this->fail('An aborted transaction must not report success.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('transaction was aborted', $e->getMessage());
        }

        $this->assertSame(0, $this->manager->getLevel());
    }

    private static function pdoError(string $state, int $code): PDOException
    {
        $e = new PDOException("SQLSTATE[$state]: database error $code");
        $e->errorInfo = [$state, $code, 'database error'];

        return $e;
    }

    public static function databaseErrors(): iterable
    {
        yield 'MySQL/MariaDB deadlock' => ['40001', 1213, true];
        yield 'MariaDB record changed since read' => ['HY000', 1020, true];
        yield 'PostgreSQL deadlock' => ['40P01', 7, true];
        yield 'lock wait timeout' => ['HY000', 1205, false];
        yield 'duplicate key' => ['23000', 1062, false];
        yield 'serialization failure without the deadlock code' => ['40001', 0, false];
    }

    #[DataProvider('databaseErrors')]
    public function testDeadlockDetection(string $state, int $code, bool $deadlock): void
    {
        $this->assertSame($deadlock, DeadlockException::isDeadlock(self::pdoError($state, $code)));
    }

    public function testDeadlockDetectionNeedsTheDatabaseErrorInfo(): void
    {
        $this->assertTrue(DeadlockException::isDeadlock(new DeadlockException()));
        $this->assertFalse(DeadlockException::isDeadlock(new PDOException('Deadlock')));
        $this->assertFalse(DeadlockException::isDeadlock(new RuntimeException('Deadlock')));
    }

    /**
     * A raw PDO statement in the function (not through the ORM executor) deadlocks: the database
     * already rolled the transaction back, and the whole function runs again.
     */
    public function testRawPdoDeadlockRerunsTheWholeTransaction(): void
    {
        $this->transactionActive = false;

        $this->pdo
            ->expects($this->exactly(2))
            ->method('beginTransaction')
            ->willReturnCallback(function () {
                $this->transactionActive = true;

                return true;
            });

        $this->pdo
            ->expects($this->once())
            ->method('commit')
            ->willReturnCallback(function () {
                $this->transactionActive = false;

                return true;
            });

        $this->pdo
            ->expects($this->never())
            ->method('rollBack');

        $calls = 0;

        $result = $this->manager->run(function () use (&$calls) {
            if (++$calls === 1) {
                $this->transactionActive = false;

                throw self::pdoError('40001', 1213);
            }

            return 'applied';
        });

        $this->assertSame('applied', $result);
        $this->assertSame(2, $calls);
        $this->assertSame(0, $this->manager->getLevel());
    }

    public function testRawPdoDeadlockInANestedScopeRerunsFromTheOutermost(): void
    {
        $this->transactionActive = false;

        $this->pdo
            ->expects($this->exactly(2))
            ->method('beginTransaction')
            ->willReturnCallback(function () {
                $this->transactionActive = true;

                return true;
            });

        $this->pdo->method('exec')->willReturn(1);

        $this->pdo
            ->expects($this->once())
            ->method('commit')
            ->willReturnCallback(function () {
                $this->transactionActive = false;

                return true;
            });

        $outer = 0;
        $inner = 0;

        $this->manager->run(function () use (&$outer, &$inner) {
            $outer++;

            $this->manager->run(function () use (&$inner) {
                if (++$inner === 1) {
                    $this->transactionActive = false;

                    throw self::pdoError('40P01', 7);
                }
            });
        });

        $this->assertSame([2, 2], [$outer, $inner]);
        $this->assertSame(0, $this->manager->getLevel());
    }

    public function testRawPdoDeadlockGivesUpAfterFourAttempts(): void
    {
        $this->transactionActive = false;

        $this->pdo
            ->expects($this->exactly(4))
            ->method('beginTransaction')
            ->willReturnCallback(function () {
                $this->transactionActive = true;

                return true;
            });

        $calls = 0;

        try {
            $this->manager->run(function () use (&$calls) {
                $calls++;
                $this->transactionActive = false;

                throw self::pdoError('40001', 1213);
            });

            $this->fail('Expected the deadlock after the last attempt.');
        } catch (PDOException $e) {
            $this->assertTrue(DeadlockException::isDeadlock($e));
        }

        $this->assertSame(4, $calls);
        $this->assertSame(0, $this->manager->getLevel());
    }

    public function testOtherPdoErrorsRollBackWithoutRerunning(): void
    {
        $failure = self::pdoError('23000', 1062);

        $this->pdo->expects($this->once())->method('beginTransaction');
        $this->pdo->expects($this->once())->method('rollBack');

        $calls = 0;

        try {
            $this->manager->run(function () use (&$calls, $failure) {
                $calls++;

                throw $failure;
            });

            $this->fail('Expected the duplicate key error.');
        } catch (PDOException $e) {
            $this->assertSame($failure, $e);
        }

        $this->assertSame(1, $calls);
        $this->assertSame(0, $this->manager->getLevel());
    }

    public function testAfterCommitRunsAtOnceOutsideATransaction(): void
    {
        $ran = false;

        $this->manager->afterCommit(function () use (&$ran) {
            $ran = true;
        });

        $this->assertTrue($ran);
    }

    public function testAfterCommitWaitsForTheOutermostCommit(): void
    {
        $this->pdo->expects($this->once())->method('commit');

        $log = [];

        $this->manager->run(function () use (&$log) {
            $this->manager->run(function () use (&$log) {
                $this->manager->afterCommit(function () use (&$log) {
                    $log[] = 'counter';
                });
            });

            $log[] = 'inner committed';
        });

        $this->assertSame(['inner committed', 'counter'], $log);
        $this->assertSame(0, $this->manager->getLevel());
    }

    public function testRollingBackAScopeDropsOnlyItsCallbacks(): void
    {
        $ran = [];

        $this->manager->run(function () use (&$ran) {
            $this->manager->afterCommit(function () use (&$ran) {
                $ran[] = 'outer';
            });

            try {
                $this->manager->run(function () use (&$ran) {
                    $this->manager->afterCommit(function () use (&$ran) {
                        $ran[] = 'inner';
                    });

                    throw new RuntimeException('inner failed');
                });
            } catch (RuntimeException) {}
        });

        $this->assertSame(['outer'], $ran);
    }

    public function testRollingBackTheTransactionDropsEveryCallback(): void
    {
        $ran = false;

        try {
            $this->manager->run(function () use (&$ran) {
                $this->manager->run(function () use (&$ran) {
                    $this->manager->afterCommit(function () use (&$ran) {
                        $ran = true;
                    });
                });

                throw new RuntimeException('outer failed');
            });
        } catch (RuntimeException) {}

        $this->assertFalse($ran);

        $this->manager->run(fn () => null);

        $this->assertFalse($ran);
    }

    public function testADeadlockRerunRegistersTheCallbackOnce(): void
    {
        $this->transactionActive = false;

        $this->pdo
            ->method('beginTransaction')
            ->willReturnCallback(function () {
                $this->transactionActive = true;

                return true;
            });

        $runs = 0;
        $calls = 0;

        $this->manager->run(function () use (&$calls, &$runs) {
            $this->manager->afterCommit(function () use (&$runs) {
                $runs++;
            });

            if (++$calls === 1) {
                $this->transactionActive = false;

                throw self::pdoError('HY000', 1020);
            }
        });

        $this->assertSame(2, $calls);
        $this->assertSame(1, $runs);
    }

    public function testAFailingCallbackDoesNotFailTheCommittedTransaction(): void
    {
        $this->pdo->expects($this->never())->method('rollBack');

        $ran = false;

        $result = $this->manager->run(function () use (&$ran) {
            $this->manager->afterCommit(function () {
                throw new RuntimeException('counter row locked');
            });
            $this->manager->afterCommit(function () use (&$ran) {
                $ran = true;
            });

            return 'saved';
        });

        $this->assertSame('saved', $result);
        $this->assertTrue($ran);
        $this->assertSame(0, $this->manager->getLevel());
    }
}

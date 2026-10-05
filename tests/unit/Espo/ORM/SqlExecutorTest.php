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

use Espo\ORM\Executor\DefaultSqlExecutor;
use Espo\ORM\PDO\PDOProvider;
use Espo\ORM\QueryComposer\MysqlQueryComposer;
use Espo\ORM\TransactionManager;

use PDO;
use PDOStatement;
use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class SqlExecutorTest extends TestCase
{
    private $pdo;
    private $sth;
    private $executor;

    protected function setUp() : void
    {
        $this->pdo = $this->createMock(PDO::class);

        $pdoProvider = $this->createMock(PDOProvider::class);
        $pdoProvider
            ->expects($this->any())
            ->method('get')
            ->willReturn($this->pdo);

        $this->sth = $this->getMockBuilder(PDOStatement::class)->disableOriginalConstructor()->getMock();

        $this->executor = new DefaultSqlExecutor($pdoProvider);
    }

    public function testExecute1()
    {
        $sql = "SOME QUERY";

        $this->pdo
            ->expects($this->once())
            ->method('query')
            ->willReturn($this->sth)
            ->with($sql);

        $sth = $this->executor->execute($sql);

        $this->assertInstanceOf(PDOStatement::class, $sth);
    }

    public function testExecuteException1()
    {
        $sql = "SOME QUERY";

        $e = new PDOException;

        $e->errorInfo = [100, 1001];

        $this->pdo
            ->expects($this->once())
            ->method('query')
            ->will($this->throwException($e));

        try {
            $this->executor->execute($sql);
        } catch (PDOException $e) {

        }
    }

    public function testExecuteException2()
    {
        $sql = "SOME QUERY";

        $e = new PDOException;

        $e->errorInfo = [100, 1001];

        $this->pdo
            ->expects($this->once())
            ->method('query')
            ->will($this->throwException($e));

        try {
            $this->executor->execute($sql, true);
        } catch (PDOException $e) {

        }
    }

    public function testExecuteDeadlock1()
    {
        $sql = "SOME QUERY";

        $e = new PDOException;

        $e->errorInfo = [100, 1001];

        $this->pdo
            ->expects($this->once())
            ->method('query')
            ->will($this->throwException($e));

        try {
            $this->executor->execute($sql);
        } catch (PDOException) {}
    }

    public function testExecuteDeadlock2()
    {
        $sql = "SOME QUERY";

        $e = new PDOException;

        $e->errorInfo = [40001, 1213];

        $invokedCount = $this->exactly(2);

        $this->pdo
            ->expects($invokedCount)
            ->method('query')
            ->willReturnCallback(function () use ($invokedCount, $e) {
                if ($invokedCount->numberOfInvocations() === 1) {
                    throw $e;
                }

                if ($invokedCount->numberOfInvocations() === 2) {
                    return $this->sth;
                }

                throw new RuntimeException();
            });

        $sth = $this->executor->execute($sql, true);

        $this->assertInstanceOf(PDOStatement::class, $sth);
    }

    public function testTransactionalDeadlockDoesNotRetryStatement(): void
    {
        $e = new PDOException('Deadlock');
        $e->errorInfo = ['40001', 1213];
        $this->pdo->method('inTransaction')->willReturn(true);
        $this->pdo->expects($this->once())->method('query')->willThrowException($e);

        $this->expectExceptionObject($e);
        $this->executor->execute('UPDATE opportunity SET status = \'Won\'', true);
    }

    public function testCaughtDeadlockCannotPersistHistoryOutsideTransaction(): void
    {
        $active = false;
        $this->pdo->method('inTransaction')->willReturnCallback(function () use (&$active) {
            return $active;
        });
        $this->pdo->method('beginTransaction')->willReturnCallback(function () use (&$active) {
            $active = true;
            return true;
        });
        $failure = new PDOException('Deadlock');
        $failure->errorInfo = ['40001', 1213];
        $this->pdo->expects($this->once())->method('query')->willReturnCallback(
            function () use (&$active, $failure) {
                $active = false; // InnoDB rolls back the entire transaction.
                throw $failure;
            }
        );
        $this->pdo->expects($this->never())->method('commit');
        $this->pdo->expects($this->never())->method('rollBack');
        $manager = new TransactionManager($this->pdo, $this->createMock(MysqlQueryComposer::class));
        $this->executor->setTransactionManager($manager);

        try {
            $manager->run(function () {
                try {
                    $this->executor->execute('UPDATE tracking_source SET total_events_received = 1', true);
                } catch (PDOException) {
                    // Best-effort analytics hooks can swallow the original failure.
                }

                $this->executor->execute('INSERT INTO opportunity_stage_history VALUES (1)', true);
            });
            $this->fail('The history write must never reach PDO after a rollback.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('transaction was aborted', $e->getMessage());
        }

        $this->assertSame(0, $manager->getLevel());
    }
}

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

namespace Espo\ORM;

use Espo\ORM\Exceptions\DeadlockException;
use Espo\ORM\QueryComposer\QueryComposer;

use PDO;
use PDOException;
use RuntimeException;
use Throwable;
use Closure;

class TransactionManager
{
    private int $level = 0;

    /** @var list<array{int, Closure}> Callbacks with the scope level that registered them. */
    private array $afterCommit = [];

    private const int MAX_ATTEMPT_COUNT = 4;

    public function __construct(private PDO $pdo, private QueryComposer $queryComposer)
    {}

    /**
     * Run a function once the outermost transaction commits, or now when none is open.
     * Rolling back the scope that registered it discards it.
     *
     * For best-effort writes to a row many transactions share, such as a counter: the row
     * is locked for one statement instead of the whole transaction. Under InnoDB snapshot
     * isolation, concurrent transactions updating the same row abort all but the first
     * to commit (error 1020). The callback handles its own errors: by the time it runs
     * the transaction is committed, so a failure must not be reported as a failed one.
     */
    public function afterCommit(Closure $callback): void
    {
        if ($this->level === 0) {
            $callback();

            return;
        }

        $this->afterCommit[] = [$this->level, $callback];
    }

    /**
     * Whether a transaction is started.
     */
    public function isStarted(): bool
    {
        return $this->level > 0;
    }

    /**
     * Get a current nesting level.
     */
    public function getLevel(): int
    {
        return $this->level;
    }

    /** Fail closed when the database has rolled back a still-open application scope. */
    public function assertActive(): void
    {
        if ($this->level > 0 && !$this->pdo->inTransaction()) {
            throw new RuntimeException('The database transaction was aborted. Retry the entire operation.');
        }
    }

    /**
     * Run a function in a transaction. Commits if success, rolls back if an exception occurs.
     *
     * @return mixed A function result.
     */
    public function run(Closure $function): mixed
    {
        return $this->runInternal($function);
    }

    /**
     * @throws PDOException
     */
    private function runInternal(Closure $function, ?int $counter = null): mixed
    {
        $counter ??= self::MAX_ATTEMPT_COUNT;

        $isOutermost = $this->level === 0;

        $this->start();

        try {
            $result = $function();

            $this->commit();
        } catch (Throwable $e) {
            // Raw PDO statements in the function report a deadlock as a plain PDOException,
            // not as the ORM executor's DeadlockException; both abort the whole transaction.
            if (!DeadlockException::isDeadlock($e)) {
                $this->rollback();

                /** @var PDOException $e */
                throw $e;
            }

            // The entire transaction is aborted, including save points.

            $this->level --;
            $this->discardAfterCommit();

            if (!$isOutermost) {
                throw $e;
            }

            $this->forceRollback();

            $counter --;

            if ($counter === 0) {
                throw $e;
            }

            return $this->runInternal($function, $counter);
        }

        return $result;
    }

    /**
     * Start a transaction.
     */
    public function start(): void
    {
        if ($this->level > 0) {
            $this->assertActive();
            $this->createSavepoint();

            $this->level++;

            return;
        }

        $this->pdo->beginTransaction();

        $this->level++;
    }

    /**
     * Commit a transaction.
     */
    public function commit(): void
    {
        if ($this->level === 0) {
            throw new RuntimeException("Can't commit not started transaction.");
        }

        $this->assertActive();

        // Keep the scope open if commit fails, so run() can roll it back and
        // preserve the original exception instead of masking it with level=0.
        if ($this->level > 1) {
            $this->releaseSavepoint($this->level - 1);
        } else {
            $this->pdo->commit();
        }

        $this->level--;

        if ($this->level > 0) {
            // A released savepoint hands its callbacks to the enclosing scope.
            $this->afterCommit = array_map(
                fn (array $entry) => [min($entry[0], $this->level), $entry[1]],
                $this->afterCommit
            );

            return;
        }

        $callbacks = $this->afterCommit;
        $this->afterCommit = [];

        foreach ($callbacks as [, $callback]) {
            try {
                $callback();
            } catch (Throwable) {
                // Already committed; see afterCommit().
            }
        }
    }

    /**
     * Rollback a transaction.
     */
    public function rollback(): void
    {
        if ($this->level === 0) {
            throw new RuntimeException("Can't rollback not started transaction.");
        }

        $this->level--;
        $this->discardAfterCommit();

        // InnoDB deadlocks already roll back every savepoint. Unwind one
        // application scope at a time; outer scopes must remain poisoned.
        if (!$this->pdo->inTransaction()) {
            return;
        }

        if ($this->level > 0) {
            $this->rollbackToSavepoint();

            return;
        }

        $this->pdo->rollBack();
    }

    /** Drop callbacks registered by scopes that are no longer open. */
    private function discardAfterCommit(): void
    {
        $this->afterCommit = array_values(
            array_filter($this->afterCommit, fn (array $entry) => $entry[0] <= $this->level)
        );
    }

    private function getCurrentSavepoint(): string
    {
        return 'POINT_' . $this->level;
    }

    private function createSavepoint(): void
    {
        $sql = $this->queryComposer->composeCreateSavepoint($this->getCurrentSavepoint());

        $this->pdo->exec($sql);
    }

    private function releaseSavepoint(int $level): void
    {
        $sql = $this->queryComposer->composeReleaseSavepoint('POINT_' . (string) $level);

        $this->pdo->exec($sql);
    }

    private function rollbackToSavepoint(): void
    {
        $sql = $this->queryComposer->composeRollbackToSavepoint($this->getCurrentSavepoint());

        $this->pdo->exec($sql);
    }

    private function forceRollback(): void
    {
        try {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
        } catch (PDOException) {}
    }
}

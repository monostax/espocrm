<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Accounting;

use Espo\ORM\Exceptions\DeadlockException;
use PDO;
use Throwable;

/**
 * A transaction the caller owns on the raw PDO, outside the ORM transaction manager. When the
 * database aborts it to break a lock cycle (another transaction took the same rows in the other
 * order), the whole callback runs again in a fresh transaction, like TransactionManager::run():
 * every lock is taken again from the start and nothing of the aborted attempt remains.
 */
final class OwnedTransaction
{
    public const ATTEMPTS = 4;

    /**
     * @template T
     * @param callable(): T $callback database work only; it may run more than once
     * @return T
     */
    public static function run(PDO $pdo, callable $callback): mixed
    {
        for ($attempt = 1; ; $attempt++) {
            $pdo->beginTransaction();
            try {
                $result = $callback();
                $pdo->commit();
                return $result;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                if ($attempt >= self::ATTEMPTS || !DeadlockException::isDeadlock($e)) throw $e;
            }
        }
    }
}

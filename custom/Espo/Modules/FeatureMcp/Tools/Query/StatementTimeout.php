<?php

declare(strict_types=1);

/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

namespace Espo\Modules\FeatureMcp\Tools\Query;

use Espo\ORM\EntityManager;
use PDO;
use PDOException;
use Throwable;

/**
 * Caps every statement an MCP query runs. A model retries quickly, and the record query budget (64 SELECTs) bounds how
 * big a query gets, not how long it runs. MariaDB only (prod); other platforms run without the cap.
 */
class StatementTimeout
{
    public const int SECONDS = 10;

    public function __construct(
        private EntityManager $entityManager,
    ) {}

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function run(callable $callback): mixed
    {
        $pdo = $this->entityManager->getPDO();
        $previous = $this->current($pdo);

        if ($previous === null) {
            return $callback();
        }

        $pdo->exec('SET SESSION max_statement_time = ' . self::SECONDS);

        try {
            return $callback();
        } finally {
            $pdo->exec('SET SESSION max_statement_time = ' . $previous);
        }
    }

    /** Whether $error, or what it wraps, is a statement stopped by the cap (MariaDB 1969). */
    public static function isTimeout(Throwable $error): bool
    {
        for ($it = $error; $it; $it = $it->getPrevious()) {
            if ($it instanceof PDOException && (int) ($it->errorInfo[1] ?? 0) === 1969) {
                return true;
            }
        }

        return false;
    }

    private function current(PDO $pdo): ?string
    {
        try {
            if (!str_contains((string) $pdo->query('SELECT VERSION()')->fetchColumn(), 'MariaDB')) {
                return null;
            }

            $value = $pdo->query('SELECT @@SESSION.max_statement_time')->fetchColumn();
        } catch (PDOException) {
            return null;
        }

        return is_numeric($value) ? (string) (float) $value : null;
    }
}

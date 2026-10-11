<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Accounting;

use InvalidArgumentException;

/** Explicit trusted configuration; no production timeout or owner is selected by this module. */
final readonly class ReconciliationPolicy
{
    public function __construct(
        public string $version,
        public string $operator,
        public int $deadlineSeconds,
        public int $retrySeconds,
        public int $minimumAttempts,
    ) {
        foreach ([$version, $operator] as $key) {
            self::key($key);
        }
        if ($retrySeconds < 1 || $deadlineSeconds < $retrySeconds || $deadlineSeconds > 31536000 ||
            $minimumAttempts < 2 || $minimumAttempts > 1000 ||
            $retrySeconds > intdiv($deadlineSeconds, $minimumAttempts - 1)) {
            throw new InvalidArgumentException('Explicit feasible reconciliation deadline, cadence and attempt count required.');
        }
    }

    public static function key(string $key): void
    {
        if (!preg_match('/^[a-z0-9][a-z0-9._:\/-]{0,127}$/D', $key)) {
            throw new InvalidArgumentException('Canonical reconciliation identity required.');
        }
    }
}

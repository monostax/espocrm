<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureJourney\Services;

use Espo\Core\Exceptions\Error;

/**
 * Caps nested journey effect storms (signal → enroll → formula → signal …).
 *
 * Only journey\* formula side-effects and UpdateTarget-from-formula enter the counter.
 * ActionRunner does not wrap — so ExecuteFormula → journey\signal costs 1 depth hop.
 *
 * Request-local static: resets per PHP worker request (jobs = fresh depth).
 */
class JourneyEffectDepth
{
    /** Max nested journey\* / updateTarget effect frames. */
    private const MAX_DEPTH = 3;

    private static int $depth = 0;

    /**
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public static function run(callable $fn): mixed
    {
        if (self::$depth >= self::MAX_DEPTH) {
            throw new Error('JourneyEffectDepth: max nested journey effect depth exceeded.');
        }

        self::$depth++;

        try {
            return $fn();
        } finally {
            self::$depth--;
        }
    }

    public static function current(): int
    {
        return self::$depth;
    }

    /** @internal tests */
    public static function reset(): void
    {
        self::$depth = 0;
    }
}

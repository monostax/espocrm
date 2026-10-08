<?php

namespace Espo\Core\Rebuild;

use Throwable;

/** Opt-in CLI diagnostics, emitted directly to container logs. */
class RebuildTiming
{
    public static function run(string $phase, callable $callback): void
    {
        if (PHP_SAPI !== 'cli' || getenv('CRM_REBUILD_TIMING') !== '1') {
            $callback();

            return;
        }

        $start = hrtime(true);
        fprintf(STDERR, "[rebuild] START %s\n", $phase);

        try {
            $callback();
        } catch (Throwable $e) {
            fprintf(STDERR, "[rebuild] FAILED %s (%.3fs)\n", $phase, (hrtime(true) - $start) / 1e9);

            throw $e;
        }

        fprintf(STDERR, "[rebuild] DONE %s (%.3fs)\n", $phase, (hrtime(true) - $start) / 1e9);
    }
}

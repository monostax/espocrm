<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Scripts;

use Espo\Core\Container;
use Espo\Core\Job\Processing\Util\ExitSetup;
use Espo\Modules\FeatureCredits\Jobs\InteractiveWork;

final class RunInteractiveWork
{
    /**
     * exec keeps open descriptors, and PHP's MariaDB socket isn't close-on-exec: each recycle left the old
     * worker's connection open and unused until wait_timeout (8 h). Six workers filled max_connections in
     * prod (2026-11-19). Bash closes every descriptor above stderr, then starts the new worker in place.
     * Bash, not sh: Debian's dash can't name descriptors above 9.
     */
    private const RECYCLE = 'for fd in /proc/$$/fd/*; do fd=${fd##*/}; ((fd > 2)) && eval "exec $fd>&-"; done; exec "$0" "$@"';

    public function run(Container $container): void
    {
        $lane = (string)getenv('CRM_CREDIT_LANE');
        $stop = false;
        (new ExitSetup())->setup(static function () use (&$stop) { $stop = true; });
        $work = $container->get('injectableFactory')->create(InteractiveWork::class);
        $recycle = time() + 300;
        while (!$stop) {
            // Recycle between jobs; never interrupt an in-flight supplier request.
            if (time() >= $recycle) {
                pcntl_exec('/usr/bin/bash', ['-c', self::RECYCLE, PHP_BINARY, ...$_SERVER['argv']]);
                throw new \RuntimeException('Could not recycle credit worker.');
            }
            $busy = $work->tick($lane);
            touch('/tmp/credit-worker-ready');
            usleep($busy ? 200000 : 1000000);
        }
    }
}

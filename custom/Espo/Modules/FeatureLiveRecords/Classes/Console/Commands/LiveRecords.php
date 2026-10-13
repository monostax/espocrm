<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureLiveRecords\Classes\Console\Commands;

use Espo\Core\Console\Command;
use Espo\Core\Console\Command\Params;
use Espo\Core\Console\IO;
use Espo\Modules\FeatureLiveRecords\Delivery\Worker;

/** Supervise `php command.php live-records`; SIGTERM exits between deliveries. */
final class LiveRecords implements Command
{
    public function __construct(private Worker $worker) {}

    public function run(Params $params, IO $io): void
    {
        $running = true;
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, static function () use (&$running) { $running = false; });
            pcntl_signal(SIGINT, static function () use (&$running) { $running = false; });
        }
        $nextMaintenance = 0;
        while ($running) {
            file_put_contents('/tmp/live-records-worker-heartbeat', (string) time());
            if (time() >= $nextMaintenance) {
                $this->worker->maintain();
                $nextMaintenance = time() + 30;
            }
            if (!$this->worker->tick()) usleep(100_000);
        }
    }
}

<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureAutomation\Jobs;

use Espo\Core\Job\JobDataLess;
use Espo\Core\Utils\Log;
use Espo\Modules\FeatureAutomation\Services\AutomationRunner;
use Throwable;

class ProcessDueAutomations implements JobDataLess
{
    public function __construct(
        private AutomationRunner $runner,
        private Log $log,
    ) {}

    public function run(): void
    {
        try {
            $this->runner->processDue();
        } catch (Throwable $e) {
            $this->log->error('ProcessDueAutomations: ' . $e->getMessage());
        }
    }
}

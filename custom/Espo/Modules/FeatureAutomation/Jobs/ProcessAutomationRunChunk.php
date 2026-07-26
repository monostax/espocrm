<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureAutomation\Jobs;

use Espo\Core\Job\Job;
use Espo\Core\Job\Job\Data;
use Espo\Core\Utils\Log;
use Espo\Modules\FeatureAutomation\Services\AutomationRunner;
use Throwable;

class ProcessAutomationRunChunk implements Job
{
    public function __construct(
        private AutomationRunner $runner,
        private Log $log,
    ) {}

    public function run(Data $data): void
    {
        $runId = $data->get('runId');
        if (!$runId || !is_string($runId)) {
            return;
        }

        $itemIds = $data->get('itemIds');
        if (!is_array($itemIds)) {
            $itemIds = null;
        }

        try {
            $this->runner->processChunk($runId, $itemIds);
        } catch (Throwable $e) {
            $this->log->error("ProcessAutomationRunChunk {$runId}: " . $e->getMessage());
        }
    }
}

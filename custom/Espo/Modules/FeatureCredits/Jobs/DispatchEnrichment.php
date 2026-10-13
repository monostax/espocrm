<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Jobs;

use Espo\Core\Job\JobDataLess;
use Espo\Core\Job\JobSchedulerFactory;
use Espo\Core\Utils\Log;
use Espo\Modules\FeatureCredits\Enrichment\Execution;
use Espo\Modules\FeatureCredits\Enrichment\ExecutionRouter;

/** Durable admitted rows are the outbox; duplicate queue deliveries never repeat a paid lookup. */
final class DispatchEnrichment implements JobDataLess
{
    public function __construct(private Execution $execution, private JobSchedulerFactory $scheduler, private Log $log,
        private ExecutionRouter $router) {}
    public function run(): void
    {
        $this->execution->expireResults();
        foreach ($this->execution->due() as $row) {
            try {
                // Deadline cleanup does not wait for a provider/worker or authorize paid I/O.
                $this->router->recover($row['tenant_id'],$row['id']);
                $this->scheduler->create()->setClassName(RunEnrichment::class)
                    ->setData(['tenantId' => $row['tenant_id'], 'quoteId' => $row['id']])
                    ->setGroup('enrichment-' . $row['id'])->schedule();
            } catch (\Throwable) {
                $this->log->error('Enrichment scheduling or cleanup failed for quote=' . $row['id']);
            }
        }
    }
}

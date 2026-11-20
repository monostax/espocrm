<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Jobs;

use Espo\Core\Job\JobSchedulerFactory;
use Espo\Core\Utils\Log;

/** The committed admission is the outbox. A queue write failure must not undo or replay the hold. */
class AdmissionJobs
{
    public function __construct(private JobSchedulerFactory $scheduler, private Log $log) {}

    public function enrichment(string $tenant, string $id): void
    {
        $this->enqueue(RunEnrichment::class, ['tenantId' => $tenant, 'quoteId' => $id], 'enrichment-' . $id);
    }

    public function verification(string $tenant, string $id): void
    {
        $this->enqueue(RunPhoneVerification::class, ['tenantId' => $tenant, 'operationId' => $id], 'phone-verification-' . $id);
    }

    private function enqueue(string $class, array $data, string $group): void
    {
        try {
            $this->scheduler->create()->setClassName($class)->setData($data)->setGroup($group)->schedule();
        } catch (\Throwable) {
            $this->log->error('Credit job enqueue failed; dispatcher will recover group=' . $group);
        }
    }
}

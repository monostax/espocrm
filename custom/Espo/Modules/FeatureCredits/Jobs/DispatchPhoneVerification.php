<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Jobs;

use Espo\Core\Job\JobDataLess;
use Espo\Core\Utils\Log;
use Espo\Modules\FeatureCredits\Accounting\Clock;
use Espo\Modules\FeatureCredits\Verification\CapabilityGate;
use Espo\Modules\FeatureCredits\Verification\Execution;
use Espo\Modules\FeatureCredits\Verification\BatchAdmissions;

/**
 * Minute sweeper for phone verification: purges expired 90-day evidence, recovers expired/cancelled holds,
 * records recovery heartbeat, and schedules unclaimed admitted operations (D12, D16, D25).
 */
final class DispatchPhoneVerification implements JobDataLess
{
    public function __construct(
        private Execution $execution,
        private CapabilityGate $capability,
        private Clock $clock,
        private AdmissionJobs $jobs,
        private Log $log,
        private BatchAdmissions $batches,
    ) {}

    public function run(): void
    {
        $this->execution->expireEvidence();
        $this->execution->diagnostics();
        $this->capability->recordRecoveryHeartbeat($this->clock->now());

        foreach ($this->execution->due() as $row) {
            try {
                $this->execution->recover($row['tenant_id'], $row['id']);
                if (in_array($row['state'], ['admitted', 'awaiting', 'claimed'], true)) {
                    $this->jobs->verification($row['tenant_id'], $row['id']);
                }
            } catch (\Throwable) {
                $this->log->error('Phone verification scheduling or recovery failed for operation=' . $row['id']);
            }
        }
        $this->batches->run();
    }
}

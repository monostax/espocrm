<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Jobs;

use Espo\Core\Job\JobDataLess;
use Espo\Core\Job\JobSchedulerFactory;
use Espo\Core\Utils\Log;
use Espo\Modules\FeatureCredits\Accounting\Clock;
use Espo\Modules\FeatureCredits\Verification\CapabilityGate;
use Espo\Modules\FeatureCredits\Verification\Execution;

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
        private JobSchedulerFactory $scheduler,
        private Log $log,
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
                    $this->scheduler->create()
                        ->setClassName(RunPhoneVerification::class)
                        ->setData(['tenantId' => $row['tenant_id'], 'operationId' => $row['id']])
                        ->setGroup('phone-verification-' . $row['id'])
                        ->schedule();
                }
            } catch (\Throwable) {
                $this->log->error('Phone verification scheduling or recovery failed for operation=' . $row['id']);
            }
        }
    }
}

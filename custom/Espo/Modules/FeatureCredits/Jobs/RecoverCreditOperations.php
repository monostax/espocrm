<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Jobs;

use Espo\Core\Job\JobDataLess;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Log;
use Espo\Modules\FeatureCredits\Accounting\DispatchStore;
use RuntimeException;
use Throwable;

/**
 * Instance-wide, CRM-run completion of unified credit operations: delivers
 * queued outcome facts and recovers sealed operations (settle or release) for
 * every tenant with due work, oldest first. Same leases and delayed retries as
 * the signed CreditDispatch `deliver`/`recoverOne` commands, which the backend's
 * Hatchet cron polled for an explicit tenant list; both can run side by side.
 * Never dispatches provider work and never infers missing facts.
 *
 * Off switch: config `featureCreditsOperationRecoveryEnabled: false`.
 */
final class RecoverCreditOperations implements JobDataLess
{
    private const TENANTS = 25;
    private const PER_TENANT = 20;
    private const BUDGET_SECONDS = 45;
    private const LEASE_MS = 60000;
    private const RETRY_MS = 120000;

    public function __construct(private DispatchStore $store, private Config $config, private Log $log) {}

    public function run(): void
    {
        if ($this->config->get('featureCreditsOperationRecoveryEnabled') === false) {
            return;
        }

        $deadline = microtime(true) + self::BUDGET_SECONDS;
        $failed = 0;

        foreach ([
            'delivery' => [$this->store->dueDeliveryTenants(self::TENANTS), $this->store->deliverNext(...)],
            'recovery' => [$this->store->dueRecoveryTenants(self::TENANTS), $this->store->recoverNext(...)],
        ] as $kind => [$tenants, $next]) {
            foreach ($tenants as $tenantId) {
                try {
                    for ($i = 0; $i < self::PER_TENANT && microtime(true) < $deadline; $i++) {
                        if (in_array($next($tenantId, self::LEASE_MS, self::RETRY_MS), ['empty', 'leaseLost'], true)) {
                            break;
                        }
                    }
                } catch (Throwable $e) {
                    $failed++;
                    $this->log->error("RecoverCreditOperations: {$kind} for tenant {$tenantId} failed: " . $e->getMessage());
                }

                if (microtime(true) >= $deadline) {
                    break 2;
                }
            }
        }

        if ($failed > 0) {
            throw new RuntimeException("Credit operation recovery failed for {$failed} tenant(s). See CRM logs.");
        }
    }
}

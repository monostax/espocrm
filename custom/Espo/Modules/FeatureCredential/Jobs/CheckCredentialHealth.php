<?php

namespace Espo\Modules\FeatureCredential\Jobs;

use Espo\Core\Job\JobDataLess;
use Espo\Core\Utils\Log;
use Espo\Modules\FeatureCredential\Tools\Credential\HealthCheckManager;
use Espo\ORM\EntityManager;

/**
 * Scheduled job that checks the health of all active credentials.
 *
 * Iterates over all active Credential records and runs the health check
 * for each one via the HealthCheckManager. Results are persisted on
 * the credential entity (lastHealthCheckAt, lastHealthCheckStatus).
 */
class CheckCredentialHealth implements JobDataLess
{
    public function __construct(
        private EntityManager $entityManager,
        private HealthCheckManager $healthCheckManager,
        private Log $log,
    ) {}

    public function run(): void
    {
        $this->log->info('FeatureCredential Module: Starting health check for all active credentials.');

        $credentials = $this->entityManager
            ->getRDBRepository('Credential')
            ->where(['isActive' => true])
            ->find();

        $total = 0;
        $healthy = 0;
        $unhealthy = 0;
        $unknown = 0;
        $errors = 0;

        foreach ($credentials as $credential) {
            $total++;
            $credentialId = $credential->getId();
            $credentialName = $credential->get('name') ?? $credentialId;

            try {
                $result = $this->healthCheckManager->checkById($credentialId);

                match ($result->status) {
                    'healthy' => $healthy++,
                    'unhealthy' => $unhealthy++,
                    default => $unknown++,
                };

                if ($result->status === 'unhealthy') {
                    $this->log->warning(
                        "CheckCredentialHealth: Credential '{$credentialName}' ({$credentialId}) is unhealthy: {$result->message}"
                    );
                }
            } catch (\Throwable $e) {
                $errors++;
                $this->log->error(
                    "CheckCredentialHealth: Error checking credential '{$credentialName}' ({$credentialId}): " . $e->getMessage()
                );
            }
        }

        $this->log->info(
            "CheckCredentialHealth: Completed. Total: {$total}, Healthy: {$healthy}, " .
            "Unhealthy: {$unhealthy}, Unknown: {$unknown}, Errors: {$errors}"
        );
    }
}

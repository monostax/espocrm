<?php

namespace Espo\Modules\FeatureIntegrationMedx\Rebuild;

use Espo\Core\ORM\Repository\Option\SaveOption;
use Espo\Core\Rebuild\RebuildAction;
use Espo\Core\Utils\Log;
use Espo\Entities\ScheduledJob;
use Espo\ORM\EntityManager;

class SeedScheduledJobs implements RebuildAction
{
    private const JOBS = [
        [
            'name' => 'MEDX: Import Clientes',
            'job' => 'FeatureIntegrationMedx/ImportClientes',
            'scheduling' => '0 2 * * *',
        ],
    ];

    /**
     * Jobs that were previously seeded but should be deactivated.
     * RebindMedxAnchorsToCredential is triggered programmatically via JobSchedulerFactory,
     * not via cron scheduling. An empty cron expression causes scheduler errors.
     */
    private const DEPRECATED_JOBS = [
        'FeatureIntegrationMedx/RebindMedxAnchorsToCredential',
    ];

    public function __construct(
        private EntityManager $entityManager,
        private Log $log,
    ) {}

    public function process(): void
    {
        foreach (self::JOBS as $jobData) {
            $this->upsertJob($jobData);
        }

        foreach (self::DEPRECATED_JOBS as $jobName) {
            $this->deactivateJob($jobName);
        }
    }

    /**
     * @param array{name: string, job: string, scheduling: string} $jobData
     */
    private function upsertJob(array $jobData): void
    {
        $existing = $this->entityManager
            ->getRDBRepository(ScheduledJob::ENTITY_TYPE)
            ->where(['job' => $jobData['job']])
            ->findOne();

        if ($existing) {
            $existing->set('name', $jobData['name']);
            $existing->set('scheduling', $jobData['scheduling']);
            $existing->set('status', ScheduledJob::STATUS_ACTIVE);

            $this->entityManager->saveEntity($existing, [SaveOption::SKIP_ALL => true]);

            $this->log->info("SeedScheduledJobs [MEDX]: Updated scheduled job '{$jobData['job']}'.");

            return;
        }

        $this->entityManager->createEntity(ScheduledJob::ENTITY_TYPE, [
            'name' => $jobData['name'],
            'job' => $jobData['job'],
            'status' => ScheduledJob::STATUS_ACTIVE,
            'scheduling' => $jobData['scheduling'],
        ], [SaveOption::SKIP_ALL => true]);

        $this->log->info("SeedScheduledJobs [MEDX]: Created scheduled job '{$jobData['job']}'.");
    }

    private function deactivateJob(string $jobName): void
    {
        $existing = $this->entityManager
            ->getRDBRepository(ScheduledJob::ENTITY_TYPE)
            ->where(['job' => $jobName])
            ->findOne();

        if ($existing && $existing->get('status') === ScheduledJob::STATUS_ACTIVE) {
            $existing->set('status', 'Inactive');
            $this->entityManager->saveEntity($existing, [SaveOption::SKIP_ALL => true]);

            $this->log->info("SeedScheduledJobs [MEDX]: Deactivated deprecated scheduled job '{$jobName}'.");
        }
    }
}

<?php

namespace Espo\Modules\FeatureIntegrationSimplesAgenda\Rebuild;

use Espo\Core\ORM\Repository\Option\SaveOption;
use Espo\Core\Rebuild\RebuildAction;
use Espo\Core\Utils\Log;
use Espo\Entities\ScheduledJob;
use Espo\ORM\EntityManager;

/**
 * Rebuild action to seed SimplesAgenda scheduled jobs.
 * Runs automatically during system rebuild.
 */
class SeedScheduledJobs implements RebuildAction
{
    private const JOBS = [
        [
            'name' => 'Sync Contacts from SimplesAgenda',
            'job' => 'SyncContactsFromSimplesAgenda',
            'scheduling' => '*/30 * * * *',
        ],
    ];

    public function __construct(
        private EntityManager $entityManager,
        private Log $log
    ) {}

    public function process(): void
    {
        foreach (self::JOBS as $jobData) {
            $this->upsertJob($jobData);
        }
    }

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
            $this->log->info("SeedScheduledJobs: Updated scheduled job '{$jobData['job']}'");
            return;
        }

        $this->entityManager->createEntity(ScheduledJob::ENTITY_TYPE, [
            'name' => $jobData['name'],
            'job' => $jobData['job'],
            'status' => ScheduledJob::STATUS_ACTIVE,
            'scheduling' => $jobData['scheduling'],
        ], [SaveOption::SKIP_ALL => true]);
        $this->log->info("SeedScheduledJobs: Created scheduled job '{$jobData['job']}'");
    }
}

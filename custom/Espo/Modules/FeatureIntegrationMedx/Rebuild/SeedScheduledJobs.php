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
        [
            'name' => 'MEDX: Rebind Anchors',
            'job' => 'FeatureIntegrationMedx/RebindMedxAnchorsToCredential',
            'scheduling' => '',
        ],
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
}

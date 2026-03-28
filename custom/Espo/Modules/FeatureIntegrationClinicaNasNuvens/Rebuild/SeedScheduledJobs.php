<?php

namespace Espo\Modules\FeatureIntegrationClinicaNasNuvens\Rebuild;

use Espo\Core\ORM\Repository\Option\SaveOption;
use Espo\Core\Rebuild\RebuildAction;
use Espo\Core\Utils\Log;
use Espo\Entities\ScheduledJob;
use Espo\ORM\EntityManager;

/**
 * Rebuild action to seed ClinicaNasNuvens scheduled jobs.
 * Runs automatically during system rebuild.
 */
class SeedScheduledJobs implements RebuildAction
{
    private const JOBS = [
        [
            'name' => 'CNN: Repair Faturado Agendamentos Without Faturamento',
            'job' => 'RepairFaturadoAgendamentosWithoutFaturamento',
            'scheduling' => '0 */6 * * *',
        ],
        [
            'name' => 'CNN: Request Data Export',
            'job' => 'DispatchRequestCnnExport',
            'scheduling' => '0 4 * * *',
        ],
        [
            'name' => 'CNN: Download Data Export',
            'job' => 'DispatchDownloadCnnExport',
            'scheduling' => '0 6 * * *',
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

            $this->log->info("SeedScheduledJobs [CNN]: Updated scheduled job '{$jobData['job']}'.");

            return;
        }

        $this->entityManager->createEntity(ScheduledJob::ENTITY_TYPE, [
            'name' => $jobData['name'],
            'job' => $jobData['job'],
            'status' => ScheduledJob::STATUS_ACTIVE,
            'scheduling' => $jobData['scheduling'],
        ], [SaveOption::SKIP_ALL => true]);

        $this->log->info("SeedScheduledJobs [CNN]: Created scheduled job '{$jobData['job']}'.");
    }
}

<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureJourney\Rebuild;

use Espo\Core\ORM\Repository\Option\SaveOption;
use Espo\Core\Rebuild\RebuildAction;
use Espo\Core\Utils\Log;
use Espo\Entities\ScheduledJob;
use Espo\ORM\EntityManager;

class SeedScheduledJobs implements RebuildAction
{
    private const DEPRECATED_JOBS = [];

    private const JOBS = [
        [
            'name' => 'Enroll Journey Records',
            'job' => 'EnrollJourneyRecords',
            'scheduling' => '* * * * *',
        ],
        [
            'name' => 'Process Journey Timers',
            'job' => 'ProcessJourneyTimers',
            'scheduling' => '* * * * *',
        ],
        [
            'name' => 'Reconcile Journey Counters',
            'job' => 'ReconcileJourneyCounters',
            'scheduling' => '0 3 * * *',
        ],
    ];

    public function __construct(
        private EntityManager $entityManager,
        private Log $log,
    ) {}

    public function process(): void
    {
        foreach (self::DEPRECATED_JOBS as $jobName) {
            $existing = $this->entityManager
                ->getRDBRepository(ScheduledJob::ENTITY_TYPE)
                ->where(['job' => $jobName])
                ->findOne();

            if ($existing && $existing->get('status') === ScheduledJob::STATUS_ACTIVE) {
                $existing->set('status', 'Inactive');
                $this->entityManager->saveEntity($existing, [SaveOption::SKIP_ALL => true]);
            }
        }

        foreach (self::JOBS as $jobData) {
            $existing = $this->entityManager
                ->getRDBRepository(ScheduledJob::ENTITY_TYPE)
                ->where(['job' => $jobData['job']])
                ->findOne();

            if ($existing) {
                $existing->set('name', $jobData['name']);
                $existing->set('scheduling', $jobData['scheduling']);
                $existing->set('status', ScheduledJob::STATUS_ACTIVE);
                $this->entityManager->saveEntity($existing, [SaveOption::SKIP_ALL => true]);
                continue;
            }

            $this->entityManager->createEntity(ScheduledJob::ENTITY_TYPE, [
                'name' => $jobData['name'],
                'job' => $jobData['job'],
                'status' => ScheduledJob::STATUS_ACTIVE,
                'scheduling' => $jobData['scheduling'],
            ], [SaveOption::SKIP_ALL => true]);

            $this->log->info("FeatureJourney SeedScheduledJobs: created {$jobData['job']}");
        }
    }
}

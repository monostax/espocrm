<?php

namespace Espo\Modules\FeatureIntegrationClinicaNasNuvens\Jobs;

use Espo\Core\Job\JobDataLess;
use Espo\Core\Job\JobSchedulerFactory;
use Espo\Core\Utils\Log;
use Espo\ORM\EntityManager;

/**
 * Cron-triggered dispatcher that finds all active CNN integration profiles
 * and queues a DownloadCnnExport worker job for each.
 */
class DispatchDownloadCnnExport implements JobDataLess
{
    public function __construct(
        private EntityManager $entityManager,
        private JobSchedulerFactory $jobSchedulerFactory,
        private Log $log,
    ) {}

    public function run(): void
    {
        $profiles = $this->entityManager
            ->getRDBRepository('FeatureIntegrationClinicaNasNuvensSettings')
            ->where([
                'isActive' => true,
                'deleted' => false,
            ])
            ->find();

        $dispatched = 0;

        foreach ($profiles as $profile) {
            $profileId = $profile->getId();

            if (!is_string($profileId) || $profileId === '') {
                continue;
            }

            $this->jobSchedulerFactory
                ->create()
                ->setClassName(DownloadCnnExport::class)
                ->setData([
                    'profileId' => $profileId,
                    'attempt' => 1,
                ])
                ->setGroup('cnn-pipeline-' . $profileId)
                ->schedule();

            $dispatched++;
        }

        $this->log->info(
            "DispatchDownloadCnnExport: Dispatched {$dispatched} DownloadCnnExport job(s)."
        );
    }
}

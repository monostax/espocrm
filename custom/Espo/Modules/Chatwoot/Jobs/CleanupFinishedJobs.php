<?php

namespace Espo\Modules\Chatwoot\Jobs;

use Espo\Core\Job\Job\Status as JobStatus;
use Espo\Core\Job\JobDataLess;
use Espo\Core\Utils\Config;
use Espo\Entities\Job;
use Espo\ORM\EntityManager;
use DateTimeImmutable;

/**
 * Per-minute scheduled jobs add ~350k job rows a day, while core Clean-up runs weekly
 * and removes them in one unbounded DELETE that holds row locks the queue needs.
 * Delete finished jobs past the retention period hourly, in small batches along
 * IDX_EXECUTE_TIME (status, execute_time) so each batch is an index range.
 */
class CleanupFinishedJobs implements JobDataLess
{
    private const BATCH = 5000;

    public function __construct(
        private EntityManager $entityManager,
        private Config $config,
    ) {}

    public function run(): void
    {
        $period = (string) $this->config->get('cleanupJobPeriod', '10 days');
        $before = (new DateTimeImmutable('-' . $period))->format('Y-m-d H:i:s');

        foreach ([JobStatus::SUCCESS, JobStatus::FAILED] as $status) {
            do {
                $delete = $this->entityManager->getQueryBuilder()->delete()
                    ->from(Job::ENTITY_TYPE)
                    ->where(['status' => $status, 'executeTime<' => $before])
                    ->limit(self::BATCH)
                    ->build();
                $deleted = $this->entityManager->getQueryExecutor()->execute($delete)->rowCount();
            } while ($deleted === self::BATCH);
        }
    }
}

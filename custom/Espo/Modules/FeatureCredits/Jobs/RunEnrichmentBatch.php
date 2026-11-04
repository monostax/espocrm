<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Jobs;

use Espo\Core\Job\Job;
use Espo\Core\Job\Job\Data;
use Espo\Core\Job\JobSchedulerFactory;
use Espo\Modules\FeatureCredits\Enrichment\BatchRunner;

/** One step of a bulk enrichment run; re-schedules itself while the run has work. */
final class RunEnrichmentBatch implements Job
{
    private const NEXT_STEP_SECONDS = 5;

    public function __construct(private BatchRunner $runner, private JobSchedulerFactory $scheduler) {}

    public function run(Data $data): void
    {
        $batch = $data->get('batchId');
        if (!is_string($batch)) throw new \InvalidArgumentException('Bulk enrichment job identity required.');
        if ($this->runner->step($batch)) self::schedule($this->scheduler, $batch, self::NEXT_STEP_SECONDS);
    }

    /** The group keeps steps of one run strictly sequential. */
    public static function schedule(JobSchedulerFactory $scheduler, string $batch, int $delay = 0): void
    {
        $job = $scheduler->create()->setClassName(self::class)->setData(['batchId' => $batch])
            ->setGroup('enrichment-batch-' . $batch);
        if ($delay > 0) $job->setDelay(new \DateInterval('PT' . $delay . 'S'));
        $job->schedule();
    }
}

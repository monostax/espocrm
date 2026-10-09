<?php
declare(strict_types=1);
namespace Espo\Modules\Chatwoot\Services;

use Espo\Core\Job\JobRunner;
use Espo\Core\Job\Job\Status;
use Espo\Entities\Job;
use Espo\ORM\EntityManager;
use Espo\Modules\Chatwoot\Tools\Stream\DispatchQueue;

/** Warm, separately resourced consumer. No cron scheduling or pool wait. */
class StreamDispatchWorker
{
    public function __construct(private EntityManager $em, private JobRunner $runner) {}

    public function run(string $queue): void
    {
        if (!in_array($queue, [DispatchQueue::AI, DispatchQueue::UI], true)) {
            throw new \InvalidArgumentException('Only dedicated stream queues are supported.');
        }
        $running = true;
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, static function () use (&$running): void { $running = false; });
        pcntl_signal(SIGINT, static function () use (&$running): void { $running = false; });
        $lastSweep = 0;
        while ($running) {
            touch('/tmp/stream-worker-heartbeat');
            if (time() - $lastSweep >= 30) {
                // Liveness kills a wedged worker before this recovery horizon.
                // Ordinary CRM retry bookkeeping consumes the existing attempts.
                $this->recover($queue);
                $lastSweep = time();
            }
            if (!$this->tick($queue)) usleep(100_000);
        }
    }

    public function tick(string $queue): bool
    {
        // Avoid range locks while idle. Recheck the candidate under its row lock.
        $where = ['queue' => $queue, 'status' => Status::PENDING, 'executeTime<=' => gmdate('Y-m-d H:i:s')];
        $candidate = $this->em->getRDBRepository('Job')->where($where)->order('number', 'ASC')->findOne();
        if (!$candidate) return false;
        $job = $this->em->getTransactionManager()->run(function () use ($candidate, $where): ?Job {
            $job = $this->em->getRDBRepository('Job')->where(['id' => $candidate->getId(), ...$where])->forUpdate()->findOne();
            if (!$job instanceof Job) return null;
            $job->setStatus(Status::RUNNING);
            $job->setStartedAtNow();
            $job->setPid(getmypid());
            $this->em->saveEntity($job);
            return $job;
        });
        if (!$job) return false;
        $started = microtime(true);
        $queuedAt = $job->getData()->queuedAt ?? null;
        $this->event($job, 'started', $queuedAt ? (int) round(($started - (float) (new \DateTimeImmutable($queuedAt))->format('U.u')) * 1000) : null);
        // Network I/O must happen only after the claim transaction commits.
        // JobRunner owns Success/Failed status; the normal retry policy remains intact.
        $this->runner->run($job);
        $this->event($job, 'finished', null, (int) round((microtime(true) - $started) * 1000));
        return true;
    }

    private function recover(string $queue): void
    {
        $query = $this->em->getQueryBuilder()->update()->in('Job')->set(['status' => Status::FAILED, 'pid' => null])
            ->where(['queue' => $queue, 'status' => Status::RUNNING,
                'startedAt<' => gmdate('Y-m-d H:i:s', time() - 120)])->build();
        $this->em->getQueryExecutor()->execute($query);
    }

    private function event(Job $job, string $stage, ?int $queueMs = null, ?int $durationMs = null): void
    {
        fwrite(STDOUT, json_encode(['event' => 'stream-dispatch', 'jobId' => $job->getId(),
            'queue' => $job->get('queue'), 'stage' => $stage, 'status' => $job->getStatus(),
            'noteId' => $job->getData()->noteId ?? null, 'queueMs' => $queueMs, 'durationMs' => $durationMs,
            'at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z')], JSON_THROW_ON_ERROR) . PHP_EOL);
    }
}

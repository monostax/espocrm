<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureIntegrationGoogleMeet\Jobs;

use Espo\Core\Application;
use Espo\Core\Application\ApplicationParams;
use Espo\Core\ApplicationUser;
use Espo\Core\InjectableFactory;
use Espo\Core\Job\Job;
use Espo\Core\Job\Job\Data;
use Espo\Core\Utils\Log;
use Espo\Entities\User;
use Espo\ORM\EntityManager;
use Espo\Modules\FeatureIntegrationGoogleMeet\Services\Import\{Failure, Files, State, Worker};

class RunMeetingGoogleImport implements Job
{
    public function __construct(private EntityManager $em, private Files $files, private State $state, private Log $log) {}

    private function actor(string $id): Worker
    {
        $container = (new Application(new ApplicationParams(noErrorHandler: true)))->getContainer();
        $user = $container->getByClass(EntityManager::class)->getEntityById('User', $id);
        if (!$user instanceof User || !$user->isActive() || (!$user->isRegular() && !$user->isAdmin())) throw new Failure('denied');
        $container->getByClass(ApplicationUser::class)->setUser($user);
        return $container->getByClass(InjectableFactory::class)->create(Worker::class);
    }

    public function run(Data $data): void
    {
        $id = (string) $data->get('attemptId');
        $candidate = $this->em->getEntityById('MeetingGoogleAttempt', $id);
        if (!$candidate || $candidate->get('status') !== 'queued') return;
        $lock = null;
        if ($candidate->get('kind') === 'recording') {
            $lock = $this->files->lock();
            if (!$lock) return; // Remains queued; maintenance redelivers, not an operation retry.
        }
        $fence = bin2hex(random_bytes(32));
        $started = microtime(true);
        $stage = 'claim';
        try {
            $attempt = $this->em->getTransactionManager()->run(function () use ($id, $fence) {
                $attempt = $this->em->getRDBRepository('MeetingGoogleAttempt')->where(['id' => $id])->forUpdate()->findOne();
                if (!$attempt || $attempt->get('status') !== 'queued') return null;
                $attempt->set(['status' => 'running', 'fence' => $fence,
                    'deadline' => gmdate('Y-m-d H:i:s', time() + ($attempt->get('kind') === 'recording' ? 3600 : 300))]);
                $this->em->saveEntity($attempt);
                foreach ($this->em->getRDBRepository('MeetingGoogleUnit')->where(['attemptId' => $id, 'status' => 'queued'])->find() as $unit) {
                    $unit->set('status', 'running');
                    $this->em->saveEntity($unit);
                }
                return $attempt;
            });
            if (!$attempt) return;
            set_time_limit($attempt->get('kind') === 'recording' ? 3660 : 360);
            $stage = 'fetch';
            $this->log->info('MeetingGoogleImport started', ['attemptId' => $id, 'kind' => $attempt->get('kind'), 'stage' => $stage]);
            $payload = $this->actor($attempt->get('initiatedById'))->fetch($id, $fence);
            // Fresh user, roles, tenancy, scopes and entity/field ACLs immediately before commit.
            $stage = 'publish';
            $this->actor($attempt->get('initiatedById'))->publish($id, $fence, $payload);
            $this->log->info('MeetingGoogleImport completed', ['attemptId' => $id, 'kind' => $attempt->get('kind'),
                'duration' => microtime(true) - $started, 'bytes' => $payload['bytes'] ?? null]);
        } catch (\Throwable $e) {
            $category = $e instanceof Failure ? $e->category : ($e instanceof \Espo\Core\Exceptions\Conflict ? 'conflict' :
                ($e instanceof \Espo\Core\Exceptions\Forbidden || $e instanceof \Espo\Core\Exceptions\NotFound ? 'denied' : 'internal'));
            $this->state->fail($id, $fence, $category);
            $this->log->warning('MeetingGoogleImport failed', ['attemptId' => $id, 'stage' => $stage, 'category' => $category, 'duration' => microtime(true) - $started]);
        } finally {
            // A failed publication can leave a renamed file; maintenance checks DB references before deleting it.
            try { $this->files->remove('meeting-stage-' . hash('sha256', $id . $fence)); }
            catch (\Throwable) { $this->log->error('MeetingGoogleImport cleanup failed', ['attemptId' => $id]); }
            if ($lock) { flock($lock, LOCK_UN); fclose($lock); }
        }
    }
}

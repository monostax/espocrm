<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureIntegrationGoogleMeet\Jobs;

use Espo\Core\Job\JobDataLess;
use Espo\Core\Utils\Log;
use Espo\ORM\EntityManager;
use Espo\ORM\Query\SelectBuilder;
use Espo\Modules\FeatureIntegrationGoogleMeet\Services\Import\{Files, State};

class MaintainMeetingGoogleImports implements JobDataLess
{
    public function __construct(private EntityManager $em, private State $state, private Files $files, private Log $log) {}

    public function run(): void
    {
        foreach ($this->em->getRDBRepository('MeetingGoogleAttempt')->where(['status' => 'running', 'deadline<' => gmdate('Y-m-d H:i:s')])->find() as $attempt) {
            $this->state->fail($attempt->getId(), (string) $attempt->get('fence'), 'deadline');
        }
        $oldest = null;
        foreach ($this->em->getRDBRepository('MeetingGoogleAttempt')->where(['status' => 'queued'])->order('createdAt')->limit(0, 100)->find() as $attempt) {
            $oldest ??= $attempt->get('createdAt');
            $this->state->schedule($attempt);
        }
        if ($oldest && $oldest < gmdate('Y-m-d H:i:s', time() - 900)) $this->log->error('MeetingGoogleImport queue delayed', ['oldestQueuedAt' => $oldest]);
        $free = disk_free_space('data/upload');
        if ($free === false || $free < 5368709120) $this->log->error('MeetingGoogleImport insufficient storage');
        // Run every minute; a 23-hour threshold guarantees removal within 24h when healthy.
        foreach (glob('data/upload/meeting-{stage,recording}-*', GLOB_BRACE) ?: [] as $path) {
            if (filemtime($path) > time() - 23 * 3600) continue;
            $key = basename($path);
            if ($this->em->getRDBRepository('MeetingGoogleUnit')->where(['storageKey' => $key, 'status' => 'succeeded'])->findOne()) continue;
            try { $this->files->remove($key); }
            catch (\Throwable) { $this->log->error('MeetingGoogleImport cleanup failed', ['file' => $key]); }
        }
        foreach ($this->em->getRDBRepository('MeetingGoogleOAuth')->where(['expiresAt<' => gmdate('Y-m-d H:i:s')])->find() as $row) {
            $this->em->getRDBRepository('MeetingGoogleOAuth')->deleteFromDb($row->getId());
        }
        // Only physical parent purge releases recordings; soft deletion retains copies for restore.
        foreach ($this->em->getRDBRepository('MeetingGoogleImport')->find() as $aggregate) {
            $query = SelectBuilder::create()->from('Meeting')->withDeleted()->where(['id' => $aggregate->get('meetingId')])->build();
            if ($this->em->getRDBRepository('Meeting')->clone($query)->findOne()) continue;
            foreach ($this->em->getRDBRepository('MeetingGoogleUnit')->where(['importId' => $aggregate->getId()])->find() as $unit) {
                if ($unit->get('storageKey')) $this->files->remove($unit->get('storageKey'));
                $this->em->getRDBRepository('MeetingGoogleUnit')->deleteFromDb($unit->getId());
            }
            foreach ($this->em->getRDBRepository('MeetingGoogleAttempt')->where(['importId' => $aggregate->getId()])->find() as $attempt) {
                $this->em->getRDBRepository('MeetingGoogleAttempt')->deleteFromDb($attempt->getId());
            }
            // Owned Documents are soft-deleted with Meeting and purged by the existing Document retention job.
            $this->em->getRDBRepository('MeetingGoogleImport')->deleteFromDb($aggregate->getId());
        }
        $this->log->info('MeetingGoogleImport maintenance heartbeat');
    }
}

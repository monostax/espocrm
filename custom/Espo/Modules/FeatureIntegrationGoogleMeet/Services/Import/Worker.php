<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureIntegrationGoogleMeet\Services\Import;

use Espo\Core\Utils\Config;
use Espo\Entities\User;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/** Construct only inside a fresh initiating-user application context, at EACH boundary. */
class Worker
{
    public function __construct(private EntityManager $em, private Access $access, private Documents $documents,
        private Google $google, private Files $files, private User $user, private Config $config) {}

    /** Readers know a session by when it started, in the initiating user's time zone. */
    private function label(string $time): string
    {
        if ($time === '') return 'Google Meet';
        $zone = $this->em->getEntityById('Preferences', $this->user->getId())?->get('timeZone') ?: $this->config->get('timeZone');
        try { return 'Google Meet — ' . (new \DateTimeImmutable($time))->setTimezone(new \DateTimeZone($zone ?: 'UTC'))->format('Y-m-d H:i'); }
        catch (\Throwable) { return 'Google Meet'; }
    }

    private function context(string $id, string $fence): array
    {
        $attempt = $this->em->getEntityById('MeetingGoogleAttempt', $id);
        if (!$attempt || $attempt->get('status') !== 'running' || !hash_equals((string) $attempt->get('fence'), $fence)) throw new Failure('staleAttempt');
        $deadline = strtotime($attempt->get('deadline') . ' UTC');
        if (microtime(true) >= $deadline) throw new Failure('deadline');
        $aggregate = $this->em->getEntityById('MeetingGoogleImport', $attempt->get('importId'));
        if (!$aggregate) throw new Failure('denied');
        $meeting = $this->access->meeting((int) $aggregate->get('accountId'), $aggregate->get('meetingId'), true);
        if ($aggregate->get('tenantId') !== $this->access->tenantId((int) $aggregate->get('accountId'))) throw new Failure('denied');
        $credential = $this->access->credential((int) $aggregate->get('accountId'), $attempt->get('credentialId'));
        if ($attempt->get('kind') === 'recording') $this->access->recording(true);
        else $this->access->text($this->documents->destination($meeting, $attempt->get('kind')));
        $units = [];
        foreach ($attempt->get('unitIds') as $unitId) {
            $unit = $this->em->getEntityById('MeetingGoogleUnit', $unitId);
            if (!$unit || $unit->get('attemptId') !== $id || $unit->get('status') !== 'running' ||
                $unit->get('importId') !== $aggregate->getId() || $unit->get('kind') !== $attempt->get('kind')) throw new Failure('staleAttempt');
            $units[] = $unit;
        }
        return [$attempt, $aggregate, $meeting, $credential->get('oAuthAccountId'), $units, $deadline];
    }

    public function fetch(string $id, string $fence): array
    {
        [$attempt, $aggregate, , $account, $units, $deadline] = $this->context($id, $fence);
        $conference = Google::conference($aggregate->get('conference'));
        $session = $this->google->get($account, Google::MEET . $conference, [], $deadline);
        if (($session['name'] ?? '') !== $conference) throw new Failure('invalidSource');
        $kind = $attempt->get('kind');
        if ($kind === 'recording') {
            $unit = $units[0];
            $artifact = $this->artifact($account, $conference, 'recordings', $unit, $deadline);
            $fileId = Google::id($artifact['driveDestination']['file'] ?? '');
            if ($fileId !== $unit->get('fileId')) throw new Failure('invalidSource');
            $metadata = $this->google->file($account, $fileId, $deadline);
            if (($metadata['id'] ?? '') !== $fileId) throw new Failure('invalidSource');
            $key = 'meeting-stage-' . hash('sha256', $id . $fence);
            $bytes = $this->google->download($account, $fileId, $this->files->path($key), $metadata, $deadline);
            return ['stagingKey' => $key, 'bytes' => $bytes];
        }
        $label = $this->label((string) ($session['startTime'] ?? ''));
        if ($kind === 'notes') {
            $documents = [];
            foreach ($units as $unit) {
                $artifact = $this->artifact($account, $conference, 'smartNotes', $unit, $deadline);
                if (($artifact['docsDestination']['document'] ?? '') !== $unit->get('documentId')) throw new Failure('invalidSource');
                $documents[$unit->get('documentId')][$unit->get('tabId')] = true;
            }
            $parts = [];
            $titles = [];
            foreach ($documents as $documentId => $selected) {
                $tabs = Text::tabs($this->google->doc($account, $documentId, $deadline));
                if (array_diff_key($selected, $tabs)) throw new Failure('selectedTabMissing');
                foreach ($tabs as $tabId => $tab) {
                    if (isset($selected[$tabId])) {
                        $parts[] = Text::notes($tab, $label);
                        $titles[$documentId . '/' . $tabId] = $tab['title'];
                    }
                }
            }
            return ['parts' => $parts, 'titles' => $titles];
        }
        $speakers = [];
        foreach ($this->google->pages($account, $conference . '/participants', 'participants', $deadline) as $participant) {
            $speakers[$participant['name']] = $participant['signedinUser']['displayName'] ?? $participant['anonymousUser']['displayName']
                ?? $participant['phoneUser']['displayName'] ?? 'Unknown speaker';
        }
        $entries = [];
        $bytes = 0;
        foreach ($units as $unit) {
            $this->artifact($account, $conference, 'transcripts', $unit, $deadline);
            foreach ($this->google->pages($account, $unit->get('resource') . '/entries', 'transcriptEntries', $deadline) as $entry) {
                if (!str_starts_with($entry['name'], $unit->get('resource') . '/entries/') ||
                    !is_string($entry['text'] ?? null) || !is_string($entry['startTime'] ?? null)) throw new Failure('invalidSource');
                $bytes += strlen($entry['text']);
                if ($bytes > 2000000) throw new Failure('tooLarge');
                $entries[$entry['name']] = $entry;
            }
        }
        if (!$entries) throw new Failure('sourceMissing');
        uasort($entries, static function ($a, $b) {
            try { $order = new \DateTimeImmutable($a['startTime']) <=> new \DateTimeImmutable($b['startTime']); }
            catch (\Throwable) { throw new Failure('invalidSource'); }
            return $order ?: strcmp($a['name'], $b['name']);
        });
        $part = Text::section($label);
        $start = strtotime($session['startTime'] ?? '');
        if ($start === false) throw new Failure('invalidSource');
        foreach ($entries as $entry) {
            $at = strtotime($entry['startTime']);
            if ($at === false) throw new Failure('invalidSource');
            $elapsed = max(0, $at - $start);
            $stamp = sprintf('%02d:%02d:%02d', intdiv($elapsed, 3600), intdiv($elapsed % 3600, 60), $elapsed % 60);
            $speaker = $speakers[$entry['participant'] ?? ''] ?? 'Unknown speaker';
            $text = '[' . $stamp . '] ' . $speaker . ': ' . $entry['text'];
            Text::add($part, Text::node($text), Text::escape($text));
        }
        return ['parts' => [$part]];
    }

    private function artifact(string $account, string $conference, string $kind, Entity $unit, float $deadline): array
    {
        $name = $unit->get('resource');
        if (!preg_match('~^' . preg_quote($conference . '/' . $kind . '/', '~') . '[A-Za-z0-9_-]+$~D', $name)) throw new Failure('invalidSource');
        $artifact = $this->google->get($account, Google::MEET . $name, [], $deadline);
        if (($artifact['name'] ?? '') !== $name || ($artifact['state'] ?? '') !== 'FILE_GENERATED') throw new Failure('sourceMissing');
        return $artifact;
    }

    public function publish(string $id, string $fence, array $payload): void
    {
        $this->em->getTransactionManager()->run(function () use ($id, $fence, $payload) {
            $initial = $this->em->getEntityById('MeetingGoogleAttempt', $id);
            $aggregate = $initial ? $this->em->getEntityById('MeetingGoogleImport', $initial->get('importId')) : null;
            if (!$aggregate) throw new Failure('staleAttempt');
            $this->em->getRDBRepository('Meeting')->where(['id' => $aggregate->get('meetingId')])->forUpdate()->findOne();
            $aggregate = $this->em->getRDBRepository('MeetingGoogleImport')->where(['id' => $aggregate->getId()])->forUpdate()->findOne();
            $this->em->getRDBRepository('MeetingGoogleAttempt')->where(['id' => $id])->forUpdate()->findOne();
            [$attempt, , $meeting, , $units] = $this->context($id, $fence);
            $warnings = [];
            if ($attempt->get('kind') === 'recording') {
                $key = 'meeting-recording-' . hash('sha256', $id . $fence);
                if (!rename($this->files->path($payload['stagingKey']), $this->files->path($key))) throw new Failure('storage');
                $units[0]->set(['storageKey' => $key, 'bytes' => (string) $payload['bytes']]);
            } else $warnings = $this->documents->publish($meeting, $aggregate, $attempt, $payload['parts']);
            foreach ($units as $unit) {
                if ($unit->get('kind') === 'notes') $unit->set('title', $payload['titles'][$unit->get('documentId') . '/' . $unit->get('tabId')] ?? $unit->get('title'));
                $unit->set(['status' => 'succeeded', 'error' => null, 'warnings' => $warnings]);
                $this->em->saveEntity($unit);
            }
            $attempt->set(['status' => 'succeeded', 'fence' => null, 'error' => null]);
            $this->em->saveEntity($attempt);
        });
    }
}

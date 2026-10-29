<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureIntegrationGoogleMeet\Services\Import;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Conflict;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Job\JobSchedulerFactory;
use Espo\Entities\User;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\Modules\FeatureIntegrationGoogleMeet\Jobs\RunMeetingGoogleImport;

class State
{
    public function __construct(private EntityManager $em, private User $user, private Access $access,
        private Discovery $discovery, private Documents $documents, private JobSchedulerFactory $jobs) {}

    public function start(int $accountId, string $meetingId, object $body): array
    {
        $this->access->assertEnabled();
        $credential = $body->credentialId ?? '';
        $conference = $body->conference ?? '';
        if (!is_string($credential) || !is_string($conference) || !is_array($body->notes ?? [])) throw new BadRequest();
        $selected = [];
        foreach ($body->notes ?? [] as $tab) {
            if (!is_object($tab) || !is_string($tab->documentId ?? null) || !is_string($tab->tabId ?? null)) throw new BadRequest('Invalid tab selection.');
            Google::id($tab->documentId);
            Google::id($tab->tabId);
            $selected[Discovery::key('notes', $tab->documentId, $tab->tabId)] = $tab;
        }
        if (!$selected && ($body->transcript ?? false) !== true && ($body->recording ?? false) !== true) throw new BadRequest('Select content to import.');
        // Revalidate source membership, not Review-time Docs/Drive content. A tab
        // removed or denied since Review fails its worker batch independently.
        $preview = $this->discovery->preview($accountId, $meetingId, $credential, $conference, false);
        $eligible = [];
        foreach ($preview['units'] as $row) {
            if ($row['kind'] === 'notesDocument') {
                if ($row['readiness'] !== 'ready' || !$preview['kinds']['notes']['allowed']) continue;
                foreach ($selected as $key => $tab) {
                    if ($tab->documentId !== ($row['documentId'] ?? null)) continue;
                    // The worker replaces the empty title with the tab's title when it reads the document.
                    $eligible['notes'][] = [...$row, 'kind' => 'notes', 'sourceKey' => $key, 'tabId' => $tab->tabId, 'title' => ''];
                    unset($selected[$key]);
                }
                continue;
            }
            $kind = $row['kind'];
            $chosen = $kind === 'notes' ? isset($selected[$row['sourceKey']]) : ($body->$kind ?? false) === true;
            if (!$chosen) continue;
            if ($kind === 'notes') unset($selected[$row['sourceKey']]);
            if (!$preview['kinds'][$kind]['allowed'] || $row['readiness'] !== 'ready') {
                if ($kind === 'notes') throw new BadRequest('Selected notes are unavailable.');
                continue;
            }
            $eligible[$kind][] = $row;
        }
        if ($selected) throw new BadRequest('Selected notes are not part of this session.');
        $this->em->getTransactionManager()->run(function () use ($accountId, $meetingId, $body, $credential, $conference, $preview, $eligible) {
            // Parent lock is also used by owned-document provisioning and deletion.
            $locked = $this->em->getRDBRepository('Meeting')->where(['id' => $meetingId])->forUpdate()->findOne();
            if (!$locked) throw new Forbidden();
            $meeting = $this->access->meeting($accountId, $meetingId, true);
            $this->access->credential($accountId, $credential);
            $aggregate = $this->documents->aggregate($meetingId);
            if ($aggregate && ($aggregate->get('conference') !== $conference || $aggregate->get('tenantId') !== $this->access->tenantId($accountId))) throw new Conflict('Source is immutable.');
            if (!$aggregate) $aggregate = $this->em->createEntity('MeetingGoogleImport', [
                'meetingId' => $meetingId, 'accountId' => $accountId, 'tenantId' => $this->access->tenantId($accountId),
                'conference' => $conference, 'conferenceStart' => $preview['startTime'],
                'credentialId' => $credential, 'initiatedById' => $this->user->getId(),
            ]);
            $reused = false;
            $created = false;
            foreach ($eligible as $kind => $rows) {
                $pending = [];
                foreach ($rows as $row) {
                    $unit = $this->em->getRDBRepository('MeetingGoogleUnit')->where([
                        'importId' => $aggregate->getId(), 'sourceKey' => $row['sourceKey'],
                    ])->findOne();
                    if ($unit && in_array($unit->get('status'), ['succeeded', 'queued', 'running'], true)) { $reused = true; continue; }
                    $values = array_intersect_key($row, array_flip(['sourceKey', 'resource', 'kind', 'documentId', 'tabId', 'title', 'readiness', 'fileId', 'bytes']));
                    if (!$unit) $unit = $this->em->getNewEntity('MeetingGoogleUnit');
                    $unit->set(['importId' => $aggregate->getId(), ...$values]);
                    $this->em->saveEntity($unit);
                    $pending[] = $unit;
                }
                if (!$pending) continue;
                $expectation = [null, null];
                if ($kind !== 'recording') {
                    $active = $this->em->getRDBRepository('MeetingGoogleAttempt')->where([
                        'importId' => $aggregate->getId(), 'kind' => $kind, 'status' => ['queued', 'running'],
                    ])->findOne();
                    if ($active) throw new Conflict('This destination already has active work.');
                    $expectation = $this->documents->expectation($meeting, $kind, $body->destinations->$kind ?? null);
                } else $this->access->recording(true);
                $batches = $kind === 'recording' ? array_map(fn ($unit) => [$unit], $pending) : [$pending];
                foreach ($batches as $units) {
                    $attempt = $this->em->createEntity('MeetingGoogleAttempt', [
                        'importId' => $aggregate->getId(), 'kind' => $kind,
                        'unitIds' => array_map(fn ($unit) => $unit->getId(), $units),
                        'credentialId' => $credential, 'initiatedById' => $this->user->getId(),
                        'expectedDocumentId' => $expectation[0], 'expectedVersion' => $expectation[1], 'status' => 'queued',
                    ]);
                    foreach ($units as $unit) {
                        $unit->set(['status' => 'queued', 'attemptId' => $attempt->getId(), 'error' => null]);
                        $this->em->saveEntity($unit);
                    }
                    $this->schedule($attempt);
                    $created = true;
                }
            }
            if (!$created && !$reused) throw new BadRequest('No ready, permitted content was selected.');
            if ($created && $aggregate->get('credentialId') !== $credential) {
                $aggregate->set('credentialId', $credential);
                $this->em->saveEntity($aggregate);
            }
            // Persist discovered artifacts, including pending clips/segments. Unselected
            // notes tabs are not import units until explicitly selected.
            foreach ($preview['units'] as $row) {
                if ($row['kind'] === 'notes') continue;
                $unit = $this->em->getRDBRepository('MeetingGoogleUnit')->where(['importId' => $aggregate->getId(), 'sourceKey' => $row['sourceKey']])->findOne();
                if ($unit && $unit->get('attemptId')) continue;
                if (!$unit) $unit = $this->em->getNewEntity('MeetingGoogleUnit');
                $unit->set(['importId' => $aggregate->getId(), ...array_intersect_key($row,
                    array_flip(['sourceKey', 'resource', 'kind', 'documentId', 'title', 'readiness', 'fileId', 'bytes']))]);
                $this->em->saveEntity($unit);
            }
        });
        return $this->status($accountId, $meetingId);
    }

    public function schedule(Entity $attempt): void
    {
        $this->em->getTransactionManager()->run(function () use ($attempt) {
            $attempt = $this->em->getRDBRepository('MeetingGoogleAttempt')->where(['id' => $attempt->getId()])->forUpdate()->findOne();
            if (!$attempt || $attempt->get('status') !== 'queued') return;
            $group = 'meeting-google-' . $attempt->getId();
            // Maintenance repairs missing delivery without growing a duplicate queue.
            // The shared-volume lock in Files serializes recording transfers deployment-wide.
            if ($this->em->getRDBRepository('Job')->where(['group' => $group, 'status' => ['Pending', 'Ready', 'Running']])->findOne()) return;
            $this->jobs->create()->setClassName(RunMeetingGoogleImport::class)->setGroup($group)
                ->setData(['attemptId' => $attempt->getId()])->schedule();
        });
    }

    public function status(int $accountId, string $meetingId): array
    {
        $meeting = $this->access->meeting($accountId, $meetingId);
        $aggregate = $this->documents->aggregate($meetingId);
        $result = ['enabled' => $this->access->enabled(), 'conference' => null, 'units' => [], 'active' => false,
            'transcriptDocumentId' => null, 'canImport' => false];
        try { $this->access->meeting($accountId, $meetingId, true); $result['canImport'] = $this->access->enabled(); }
        catch (\Throwable) {}
        if (!$aggregate) return $result;
        if ($aggregate->get('tenantId') !== $this->access->tenantId($accountId)) throw new Forbidden();
        $result['conference'] = $aggregate->get('conference');
        $result['conferenceStart'] = $aggregate->get('conferenceStart');
        $result['credentialId'] = $aggregate->get('credentialId');
        foreach ($this->em->getRDBRepository('MeetingGoogleUnit')->where(['importId' => $aggregate->getId()])->order('createdAt')->find() as $unit) {
            $kind = $unit->get('kind');
            if ($kind === 'notesDocument' || !$unit->get('attemptId')) continue;
            try {
                if ($kind === 'recording') $this->access->recording();
                else {
                    $document = $this->documents->destination($meeting, $kind);
                    if ($document) $this->access->text($document, false);
                    else $this->access->text(null);
                    if ($kind === 'transcript') $result['transcriptDocumentId'] = $document?->getId();
                }
            } catch (\Throwable) { continue; }
            $active = in_array($unit->get('status'), ['queued', 'running'], true);
            $result['active'] = $result['active'] || $active;
            $result['units'][] = ['id' => $unit->getId(), 'kind' => $kind, 'title' => $unit->get('title'),
                'resource' => $unit->get('resource'), 'documentId' => $unit->get('documentId'), 'tabId' => $unit->get('tabId'),
                'status' => $unit->get('status'), 'error' => $unit->get('error'), 'bytes' => $unit->get('bytes'),
                'warnings' => $unit->get('warnings') ?? [], 'retryable' => !$active && $unit->get('status') !== 'succeeded' && $result['canImport']];
        }
        return $result;
    }

    public function fail(string $id, string $fence, string $category): void
    {
        $this->em->getTransactionManager()->run(function () use ($id, $fence, $category) {
            $attempt = $this->em->getRDBRepository('MeetingGoogleAttempt')->where(['id' => $id])->forUpdate()->findOne();
            if (!$attempt || $attempt->get('status') !== 'running' || $attempt->get('fence') !== $fence) return;
            $attempt->set(['status' => 'failed', 'error' => $category, 'fence' => null]);
            $this->em->saveEntity($attempt);
            foreach ($this->em->getRDBRepository('MeetingGoogleUnit')->where(['attemptId' => $id, 'status' => 'running'])->find() as $unit) {
                $unit->set(['status' => 'failed', 'error' => $category]);
                $this->em->saveEntity($unit);
            }
        });
    }
}

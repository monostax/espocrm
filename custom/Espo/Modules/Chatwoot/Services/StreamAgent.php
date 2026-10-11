<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Services;

use Espo\Core\Acl;
use Espo\Core\AclManager;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\ORM\Repository\Option\SaveOption;
use Espo\Entities\Note;
use Espo\Entities\User;
use Espo\Modules\Chatwoot\Tools\Stream\OpportunityAccess;
use Espo\Modules\Chatwoot\Tools\Activities\Access as ActivityAccess;
use Espo\ORM\EntityManager;
use Espo\ORM\Query\SelectBuilder;
use Espo\Tools\Stream\NoteUtil;
use Espo\Modules\Global\Tools\Tenant\UserTenantResolver;

/** The AI's linked CRM User is the authenticated actor for both actions. */
class StreamAgent
{
    public function __construct(
        private EntityManager $entityManager,
        private User $user,
        private Acl $acl,
        private OpportunityAccess $access,
        private NoteUtil $noteUtil,
        private ActivityAccess $activityAccess,
        private StreamAgentProgress $progress,
        private AclManager $aclManager,
        private UserTenantResolver $tenants,
        private \Espo\Modules\FeatureAiSession\Services\Execution $sessions,
        private \Espo\Core\FileStorage\Manager $files,
        private StreamAgentLive $live,
    ) {}

    /** Runtime-only media transport: never embed bytes/URLs in workflow context. */
    public function attachments(string $noteId, string $membershipId, string $postHash, string $runId,
        string $postId, ?string $attachmentId): object
    {
        $source = $this->entityManager->getEntityById('Note', $noteId);
        $target = $source instanceof Note ? $this->validate($source, $membershipId, $postHash) : null;
        if (!$source instanceof Note || !$target || ($source->getData()->opportunityAiExecutions->{$membershipId} ?? null) !== $runId) {
            throw new Forbidden('An active claimed stream execution is required.');
        }
        $reply = $this->existingReply($noteId, $membershipId);
        if ($reply && !StreamAgentProgress::isPending($reply)) throw new Forbidden('Execution has finished.');
        $initiatorId = $source->getData()->opportunityAiInitiatorUserId ?? null;
        $humanId = $this->delegatedInitiator($source, $target);
        if ($initiatorId !== null && !$humanId) throw new Forbidden('Source access was revoked.');
        $reader = $humanId ? $this->entityManager->getEntityById('User', $humanId) : $this->user;
        $post = $this->entityManager->getEntityById('Note', $postId);
        if (!$reader instanceof User || !$post instanceof Note || $post->getType() !== Note::TYPE_POST ||
            $post->get('opportunityPostDeleted') || $post->getParentType() !== $source->getParentType() ||
            $post->getParentId() !== $source->getParentId() ||
            ($postId !== $noteId && (int) $post->get('number') >= (int) $source->get('number')) ||
            ($source->getParentType() !== 'AiSession' && $postId !== $source->get('opportunityThreadRootId') &&
                $post->get('opportunityThreadRootId') !== $source->get('opportunityThreadRootId')) ||
            !$this->aclManager->checkEntityRead($reader, $post)) {
            throw new Forbidden('Attachment source is outside the readable discussion.');
        }
        $items = [];
        foreach ($this->entityManager->getRDBRepository('Note')->getRelation($post, 'attachments')->find() as $attachment) {
            if (!$this->aclManager->checkField($reader, 'Note', 'attachments') ||
                !$attachment instanceof \Espo\Entities\Attachment || !$this->aclManager->checkEntityRead($reader, $attachment) ||
                !$this->aclManager->checkField($reader, 'Attachment', 'type') ||
                !$this->aclManager->checkField($reader, 'Attachment', 'size')) continue;
            $item = ['id' => $attachment->getId(),
                'name' => $this->aclManager->checkField($reader, 'Attachment', 'name') ? $attachment->get('name') : null,
                'type' => $attachment->get('type'), 'size' => (int) $attachment->get('size')];
            if ($attachmentId === $attachment->getId()) {
                $maxBytes = 12 * 1024 * 1024;
                if ($item['size'] <= 0 || $item['size'] > $maxBytes) throw new Forbidden('Attachment exceeds media limit.');
                $stream = $this->files->getStream($attachment);
                $bytes = '';
                while (!$stream->eof() && strlen($bytes) <= $maxBytes) {
                    $chunk = $stream->read(min(65536, $maxBytes + 1 - strlen($bytes)));
                    if ($chunk === '') break;
                    $bytes .= $chunk;
                }
                if (strlen($bytes) !== $item['size'] || strlen($bytes) > $maxBytes) throw new Forbidden('Attachment size changed.');
                return (object) [...$item, 'revision' => hash('sha256', $bytes), 'data' => base64_encode($bytes)];
            }
            $items[] = (object) $item;
            if ($attachmentId === null && count($items) >= 20) break;
        }
        if ($this->aclManager->checkField($reader, 'Note', 'post')) {
            $account = $this->entityManager->getEntityById('ChatwootAccount', $target->chatwootAccountCrmId);
            $platformId = $account?->get('platformId');
            $platform = $platformId ? $this->entityManager->getEntityById('ChatwootPlatform', $platformId) : null;
            foreach (\Espo\Modules\Chatwoot\Tools\Stream\InlineMedia::fromPost($post->getPost() ?? '',
                (string) $platform?->get('frontendUrl'), (string) $platform?->get('backendUrl')) as $item) {
                if ($attachmentId === $item['id']) return (object) $item;
                if (count($items) >= 20) break;
                unset($item['storageUrl']); // The signed locator is returned only on an authorized load.
                $items[] = (object) $item;
            }
        }
        if ($attachmentId !== null) throw new Forbidden('Attachment is not linked to the readable source.');
        return (object) ['list' => $items];
    }

    public function context(string $noteId, string $membershipId, string $postHash): object
    {
        $note = $this->entityManager->getEntityById('Note', $noteId);
        $target = $note instanceof Note ? $this->validate($note, $membershipId, $postHash) : null;
        if (!$target) {
            return (object) ['shouldRespond' => false, 'reason' => 'Trigger no longer valid'];
        }
        $existing = $this->existingReply($noteId, $membershipId);
        if ($existing && !StreamAgentProgress::isPending($existing)) {
            return (object) ['shouldRespond' => false, 'reason' => 'Already replied'];
        }
        return (object) [
            'shouldRespond' => true,
            'parentType' => $note->getParentType(),
            'parentId' => $note->getParentId(),
            ...($note->getParentType() === 'Opportunity' ? ['opportunityId' => $note->getParentId()] : []),
            'threadRootId' => $note->get('opportunityThreadRootId'),
            'crmTenantId' => $target->crmTenantId,
            'chatwootAccountCrmId' => $target->chatwootAccountCrmId,
            'executionRunId' => $note->getData()->opportunityAiExecutions->{$membershipId} ?? null,
            'initiatorUserId' => $this->delegatedInitiator($note, $target),
            ...($note->getParentType() === 'AiSession' ? $this->sessions->context($note) : []),
            'trigger' => $note->getParentType() === 'AiSession' ? $this->sessions->post($note) : (object) [
                'id' => $note->getId(),
                'post' => $note->getPost(),
                'number' => $note->get('number'),
                'createdAt' => $note->get('createdAt'),
                'createdByName' => $note->get('createdByName'),
            ],
        ];
    }

    public function reply(string $noteId, string $membershipId, string $postHash, string $post, string $runId): object
    {
        $result = $this->entityManager->getTransactionManager()->run(function () use (
            $noteId, $membershipId, $postHash, $post, $runId,
        ): object {
            // All retries for this source post serialize here, including a lost HTTP response.
            $source = $this->entityManager->getRDBRepository('Note')->where(['id' => $noteId])->forUpdate()->findOne();
            $target = $source instanceof Note ? $this->validate($source, $membershipId, $postHash) : null;
            if (!$target) {
                return (object) ['published' => false, 'noteId' => null, 'reason' => 'Trigger no longer valid'];
            }
            $existing = $this->existingReply($noteId, $membershipId);
            if ($existing && !StreamAgentProgress::isPending($existing)) {
                return (object) ['published' => false, 'noteId' => $existing->getId(), 'reason' => 'Already replied'];
            }
            $owner = $source->getData()->opportunityAiExecutions->{$membershipId} ?? null;
            if ($source->getParentType() === 'AiSession' && $owner !== $runId) {
                throw new Forbidden('Claim the session turn before publishing.');
            }
            if ($owner !== null && $owner !== $runId) {
                throw new Forbidden('Another workflow owns this request.');
            }
            if ($existing) {
                if ($owner !== $runId) throw new Forbidden('Claim the request before completing it.');
                if ($source->getParentType() !== 'AiSession' && !$this->acl->check($existing, 'create')) throw new Forbidden('No permission to post in this stream.');
                $data = $existing->getData();
                $data->opportunityStreamAgent->status = 'completed';
                unset($data->opportunityStreamAgent->draft);
                $data->opportunityStreamAgent->finishedAt = gmdate('Y-m-d\TH:i:s\Z');
                $existing->setData($data);
                $existing->set('createdById', $this->user->getId());
                $existing->setPost($post);
                $this->noteUtil->handlePostText($existing);
                $this->entityManager->saveEntity($existing);
                return (object) ['published' => true, 'noteId' => $existing->getId(), 'reason' => 'Replied'];
            }
            $reply = $this->entityManager->getNewEntity('Note');
            assert($reply instanceof Note);
            $reply->set([
                'type' => Note::TYPE_POST,
                'parentType' => $source->getParentType(),
                'parentId' => $source->getParentId(),
                'opportunityThreadRootId' => $source->get('opportunityThreadRootId'),
                'post' => $post,
                'isInternal' => true,
                'createdById' => $this->user->getId(),
                'opportunityChatwootAccountId' => $source->get('opportunityChatwootAccountId'),
                'opportunityStreamEventKey' => $this->replyKey($noteId, $membershipId),
                'data' => (object) [
                    'opportunityStreamAgent' => (object) [
                        'sourceNoteId' => $noteId,
                        'aiAgentMembershipId' => $membershipId,
                        'workflowRunId' => $runId,
                        'status' => 'completed',
                    ],
                ],
            ]);
            if ($source->getParentType() !== 'AiSession' && !$this->acl->check($reply, 'create')) {
                throw new Forbidden('No permission to post in this stream.');
            }
            $this->noteUtil->handlePostText($reply);
            // Normal ORM hooks update read state, mentions and the ActionCable invalidation job.
            $this->entityManager->saveEntity($reply);
            return (object) ['published' => true, 'noteId' => $reply->getId(), 'reason' => 'Replied'];
        });
        if ($result->published) $this->live->publish($this->existingReply($noteId, $membershipId));
        return $result;
    }

    /** One execution per mention, before any external side effects. No lease expiry/replay. */
    public function claim(string $noteId, string $membershipId, string $postHash, string $runId): object
    {
        return $this->entityManager->getTransactionManager()->run(function () use ($noteId, $membershipId, $postHash, $runId): object {
            $source = $this->entityManager->getRDBRepository('Note')->where(['id' => $noteId])->forUpdate()->findOne();
            $existing = $this->existingReply($noteId, $membershipId);
            if (!$source instanceof Note || !$this->validate($source, $membershipId, $postHash) ||
                ($existing && !StreamAgentProgress::isPending($existing))) {
                return (object) ['claimed' => false];
            }
            $data = $source->getData();
            if (isset($data->opportunityAiExecutions->{$membershipId})) {
                return (object) ['claimed' => false];
            }
            $reply = $this->entityManager->getNewEntity('Note');
            $reply->set([
                'type' => Note::TYPE_POST, 'parentType' => $source->getParentType(),
                'parentId' => $source->getParentId(), 'isInternal' => true,
                'createdById' => $this->user->getId(),
            ]);
            if ($source->getParentType() !== 'AiSession' && !$this->acl->check($reply, 'create')) {
                throw new Forbidden('No permission to report results in this stream.');
            }
            $data->opportunityAiExecutions ??= (object) [];
            $data->opportunityAiExecutions->{$membershipId} = $runId;
            $source->setData($data);
            // Execution bookkeeping is not a human edit. Preserve modifiedAt
            // and modifiedBy (including any genuine earlier edit).
            $this->entityManager->saveEntity($source, [SaveOption::SKIP_MODIFIED_BY => true]);
            if ($existing) {
                $replyData = $existing->getData();
                $replyData->opportunityStreamAgent->status = 'running';
                $replyData->opportunityStreamAgent->workflowRunId = $runId;
                $replyData->opportunityStreamAgent->startedAt = gmdate('Y-m-d\TH:i:s\Z');
                $replyData->opportunityStreamAgent->phase = 'preparing';
                $replyData->opportunityStreamAgent->sequence = 0;
                $replyData->opportunityStreamAgent->activities = [];
                $replyData->opportunityStreamAgent->thinking = '';
                $existing->setData($replyData);
                // Repair placeholders created before explicit ORM attribution.
                $existing->set('createdById', $this->user->getId());
                $existing->setPost('Working on your request…');
                $this->entityManager->saveEntity($existing);
                $this->progress->scheduleExpiry($existing);
            }
            return (object) ['claimed' => true];
        });
    }

    /** Terminal feedback may outlive an edited trigger, but must belong to this AI/run. */
    public function status(string $noteId, string $membershipId, string $runId, string $status, ?string $reason = null): object
    {
        $result = $this->entityManager->getTransactionManager()->run(function () use ($noteId, $membershipId, $runId, $status, $reason): object {
            $source = $this->entityManager->getRDBRepository('Note')->where(['id' => $noteId])->forUpdate()->findOne();
            $reply = $this->existingReply($noteId, $membershipId);
            if (!StreamAgentProgress::isPending($reply)) return (object) ['updated' => false];
            $sessionAccess = $source instanceof Note && $source->getParentType() === 'AiSession'
                ? $this->sessions->authorized($source, $membershipId, $this->user) : $this->acl->check($reply, 'read');
            if ($reply->getCreatedById() !== $this->user->getId() || !$this->user->isActive() || !$sessionAccess) {
                throw new Forbidden('Authenticate as the mentioned AI profile.');
            }
            $data = $reply->getData();
            $owner = $source?->getData()->opportunityAiExecutions->{$membershipId} ?? $data->opportunityStreamAgent->workflowRunId ?? null;
            if ($owner !== null && $owner !== $runId) return (object) ['updated' => false];
            $post = match ($status) {
                'cancelled' => 'This request was stopped or replaced by a newer mention.',
                'blocked' => $reason === 'insufficient_credits'
                    ? 'This request was stopped because there are not enough AI credits. Completed AI work remains billable. Ask a tenant administrator to add credits before trying again.'
                    : 'This request could not start because the AI budget is unavailable.',
                'failed' => 'This request could not finish. Check the workflow before requesting another execution.',
                default => throw new \InvalidArgumentException('Invalid stream agent status.'),
            };
            $data->opportunityStreamAgent->status = $status;
            unset($data->opportunityStreamAgent->draft);
            $data->opportunityStreamAgent->finishedAt = gmdate('Y-m-d\TH:i:s\Z');
            $reply->setData($data);
            $reply->setPost($post);
            $this->entityManager->saveEntity($reply);
            return (object) ['updated' => true];
        });
        if ($result->updated) $this->live->publish($this->existingReply($noteId, $membershipId));
        return $result;
    }

    public function updateProgress(string $noteId, string $membershipId, string $runId, object $snapshot): object
    {
        $result = $this->entityManager->getTransactionManager()->run(function () use ($noteId, $membershipId, $runId, $snapshot): object {
            // Serialize with claim, publication, cancellation and expiry; terminal states never regress.
            $source = $this->entityManager->getRDBRepository('Note')->where(['id' => $noteId])->forUpdate()->findOne();
            $reply = $this->existingReply($noteId, $membershipId);
            if (!$source instanceof Note || !StreamAgentProgress::isPending($reply)) return (object) ['updated' => false];
            $data = $reply->getData();
            $progress = $data->opportunityStreamAgent;
            if (($source->getData()->opportunityAiExecutions->{$membershipId} ?? null) !== $runId ||
                ($progress->workflowRunId ?? null) !== $runId || $progress->status !== 'running' ||
                $snapshot->sequence <= ($progress->sequence ?? 0)) return (object) ['updated' => false];
            $sessionAccess = $source->getParentType() === 'AiSession'
                ? $this->sessions->authorized($source, $membershipId, $this->user) : $this->acl->check($reply, 'read');
            if ($reply->getCreatedById() !== $this->user->getId() || !$this->user->isActive() || !$sessionAccess) {
                throw new Forbidden('Authenticate as the mentioned AI profile.');
            }
            if (isset($snapshot->draft)) {
                $target = $this->validate($source, $membershipId, $snapshot->postHash);
                if (!$target || (isset($source->getData()->opportunityAiInitiatorUserId) && !$this->delegatedInitiator($source, $target))) {
                    throw new Forbidden('Source access was revoked or the request changed.');
                }
                $progress->draft = $snapshot->draft;
                if ($snapshot->draft !== '' && !isset($progress->firstTextAt)) {
                    $progress->firstTextAt = (new \DateTimeImmutable())->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.v\Z');
                }
            }
            $progress->sequence = $snapshot->sequence;
            $progress->phase = $snapshot->phase;
            $progress->activities = $snapshot->activities;
            $progress->thinking = $snapshot->thinking ?? '';
            $reply->setData($data);
            // Preview snapshots are transient UI state, not new discussion
            // activity. Avoid a global inbox invalidation job for every chunk.
            $this->entityManager->saveEntity($reply, [SaveOption::SKIP_MODIFIED_BY => true,
                SaveOption::SKIP_HOOKS => true]);
            return (object) ['updated' => true];
        });
        if ($result->updated) $this->live->publish($this->existingReply($noteId, $membershipId));
        return $result;
    }

    public function subscribe(string $noteId, int $accountId, int $userId, string $pubsubToken): object
    {
        $this->preview($noteId); // Reauthorize on every short-lived grant renewal.
        $reply = $this->entityManager->getEntityById('Note', $noteId);
        if (!$reply instanceof Note) throw new Forbidden('Live replies are unavailable.');
        return $this->live->ticket($reply, $accountId, $userId, $pubsubToken);
    }

    /** Small, ACL-checked live snapshot. The final post still uses normal publication. */
    public function preview(string $noteId): object
    {
        $reply = $this->entityManager->getEntityById('Note', $noteId);
        if (!$reply instanceof Note || $reply->get('opportunityPostDeleted') || !$this->acl->check($reply, 'read')) {
            throw new Forbidden('No access to this reply.');
        }
        $progress = clone ($reply->getData()->opportunityStreamAgent ?? (object) []);
        if (!StreamAgentProgress::isPending($reply)) unset($progress->draft);
        return (object) ['progress' => $progress,
            'post' => ($progress->status ?? null) === 'completed' ? $reply->getPost() : null];
    }

    /** Null is deliberate for legacy/revoked triggers; callers must never substitute the AI. */
    private function delegatedInitiator(Note $note, object $target): ?string
    {
        $id = $note->getData()->opportunityAiInitiatorUserId ?? null;
        if (!is_string($id) || $id === '' || $id !== $note->getCreatedById()) return null;
        $initiator = $this->entityManager->getEntityById('User', $id);
        $parent = $this->entityManager->getEntityById($note->getParentType(), $note->getParentId());
        if (!$initiator instanceof User || !$initiator->isActive() || $initiator->isApi() || $initiator->isPortal() ||
            !$parent || !$this->tenants->canActForTenant($initiator, $target->crmTenantId) ||
            !$this->aclManager->checkEntityRead($initiator, $parent) ||
            !$this->aclManager->checkEntityStream($initiator, $parent) ||
            !$this->aclManager->checkEntityRead($initiator, $note)) return null;
        return $id;
    }

    private function validate(Note $note, string $membershipId, string $postHash): ?object
    {
        if ($note->getType() !== Note::TYPE_POST ||
            !in_array($note->getParentType(), ['Opportunity', ...ActivityDiscussion::PARENT_TYPES], true) ||
            $note->get('opportunityPostDeleted') ||
            ($note->getData()->opportunityStreamAgent ?? null) ||
            !hash_equals(hash('sha256', $note->getPost() ?? ''), $postHash)) {
            return null;
        }
        $target = null;
        foreach ($note->getData()->opportunityAiMentionTargets ?? [] as $candidate) {
            if ($candidate->aiAgentMembershipId === $membershipId) {
                $target = $candidate;
                break;
            }
        }
        if (!$target) {
            return null;
        }
        if ($note->getParentType() === 'AiSession') {
            return $this->sessions->authorized($note, $membershipId, $this->user) ? $target : null;
        }
        $membership = $this->entityManager->getEntityById('ChatwootAccountUserMembership', $membershipId);
        if (!$membership?->get('isAI') || $membership->get('chatwootAccountId') !== $target->chatwootAccountCrmId) {
            return null;
        }
        $chatwootUser = $this->entityManager->getEntityById('ChatwootUser', $membership->get('chatwootUserId'));
        if (!$chatwootUser || $chatwootUser->get('assignedUserId') !== $this->user->getId() || !$this->user->isActive()) {
            throw new Forbidden('Authenticate as the mentioned AI profile.');
        }
        $account = $this->entityManager->getEntityById('ChatwootAccount', $target->chatwootAccountCrmId);
        $parent = $this->entityManager->getEntityById($note->getParentType(), $note->getParentId());
        if (!$account || !$parent || $account->get('tenantId') !== $target->crmTenantId ||
            $chatwootUser->get('platformId') !== $account->get('platformId')) {
            return null;
        }
        if (in_array($note->getParentType(), ActivityAccess::TYPES, true)) {
            $tenant = $this->entityManager->getEntityById('Tenant', $target->crmTenantId);
            if (!$tenant) return null;
            $this->activityAccess->record($note->getParentType(), $note->getParentId(), $tenant, true);
        } elseif ($parent->get('tenantId') !== $target->crmTenantId) {
            return null;
        }
        if (!$this->access->canReadNote($this->user, $note) || !$this->acl->check($note, 'read')) {
            throw new Forbidden('No permission to read this stream.');
        }
        return $target;
    }

    private function replyKey(string $noteId, string $membershipId): string
    {
        return hash('sha256', "opportunity-stream-agent:$noteId:$membershipId");
    }

    private function existingReply(string $noteId, string $membershipId): ?Note
    {
        // A human deleting the response must not cause a redelivered webhook to resurrect it.
        $query = SelectBuilder::create()->from('Note')->withDeleted()
            ->where(['opportunityStreamEventKey' => $this->replyKey($noteId, $membershipId)])->build();
        return $this->entityManager->getRDBRepository('Note')->clone($query)->findOne();
    }
}

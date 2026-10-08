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
    ) {}

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
            'trigger' => (object) [
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
        return $this->entityManager->getTransactionManager()->run(function () use (
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
            if ($owner !== null && $owner !== $runId) {
                throw new Forbidden('Another workflow owns this request.');
            }
            if ($existing) {
                if ($owner !== $runId) throw new Forbidden('Claim the request before completing it.');
                if (!$this->acl->check($existing, 'create')) throw new Forbidden('No permission to post in this stream.');
                $data = $existing->getData();
                $data->opportunityStreamAgent->status = 'completed';
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
            if (!$this->acl->check($reply, 'create')) {
                throw new Forbidden('No permission to post in this stream.');
            }
            $this->noteUtil->handlePostText($reply);
            // Normal ORM hooks update read state, mentions and the ActionCable invalidation job.
            $this->entityManager->saveEntity($reply);
            return (object) ['published' => true, 'noteId' => $reply->getId(), 'reason' => 'Replied'];
        });
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
            if (!$this->acl->check($reply, 'create')) {
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
    public function status(string $noteId, string $membershipId, string $runId, string $status): object
    {
        return $this->entityManager->getTransactionManager()->run(function () use ($noteId, $membershipId, $runId, $status): object {
            $source = $this->entityManager->getRDBRepository('Note')->where(['id' => $noteId])->forUpdate()->findOne();
            $reply = $this->existingReply($noteId, $membershipId);
            if (!StreamAgentProgress::isPending($reply)) return (object) ['updated' => false];
            if ($reply->getCreatedById() !== $this->user->getId() || !$this->user->isActive() || !$this->acl->check($reply, 'read')) {
                throw new Forbidden('Authenticate as the mentioned AI profile.');
            }
            $data = $reply->getData();
            $owner = $source?->getData()->opportunityAiExecutions->{$membershipId} ?? $data->opportunityStreamAgent->workflowRunId ?? null;
            if ($owner !== null && $owner !== $runId) return (object) ['updated' => false];
            $post = match ($status) {
                'cancelled' => 'This request was stopped or replaced by a newer mention.',
                'blocked' => 'This request could not start because the AI budget is unavailable.',
                'failed' => 'This request could not finish. Check the workflow before requesting another execution.',
                default => throw new \InvalidArgumentException('Invalid stream agent status.'),
            };
            $data->opportunityStreamAgent->status = $status;
            $reply->setData($data);
            $reply->setPost($post);
            $this->entityManager->saveEntity($reply);
            return (object) ['updated' => true];
        });
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

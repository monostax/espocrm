<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Services;

use Espo\Core\Acl;
use Espo\Core\Exceptions\Forbidden;
use Espo\Entities\Note;
use Espo\Entities\User;
use Espo\Modules\Chatwoot\Tools\Stream\OpportunityAccess;
use Espo\ORM\EntityManager;
use Espo\ORM\Query\SelectBuilder;
use Espo\Tools\Stream\NoteUtil;

/** The AI's linked CRM User is the authenticated actor for both actions. */
class OpportunityStreamAgent
{
    public function __construct(
        private EntityManager $entityManager,
        private User $user,
        private Acl $acl,
        private OpportunityAccess $access,
        private NoteUtil $noteUtil,
    ) {}

    public function context(string $noteId, string $membershipId, string $postHash): object
    {
        $note = $this->entityManager->getEntityById('Note', $noteId);
        $target = $note instanceof Note ? $this->validate($note, $membershipId, $postHash) : null;
        if (!$target) {
            return (object) ['shouldRespond' => false, 'reason' => 'Trigger no longer valid'];
        }
        $existing = $this->existingReply($noteId, $membershipId);
        if ($existing) {
            return (object) ['shouldRespond' => false, 'reason' => 'Already replied'];
        }
        return (object) [
            'shouldRespond' => true,
            'opportunityId' => $note->getParentId(),
            'crmTenantId' => $target->crmTenantId,
            'chatwootAccountCrmId' => $target->chatwootAccountCrmId,
            'executionRunId' => $note->getData()->opportunityAiExecutions->{$membershipId} ?? null,
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
            if ($existing = $this->existingReply($noteId, $membershipId)) {
                return (object) ['published' => false, 'noteId' => $existing->getId(), 'reason' => 'Already replied'];
            }
            $owner = $source->getData()->opportunityAiExecutions->{$membershipId} ?? null;
            if ($owner !== null && $owner !== $runId) {
                throw new Forbidden('Another workflow owns this request.');
            }
            $reply = $this->entityManager->getNewEntity('Note');
            assert($reply instanceof Note);
            $reply->set([
                'type' => Note::TYPE_POST,
                'parentType' => 'Opportunity',
                'parentId' => $source->getParentId(),
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
                    ],
                ],
            ]);
            if (!$this->acl->check($reply, 'create')) {
                throw new Forbidden('No permission to post in this opportunity.');
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
            if (!$source instanceof Note || !$this->validate($source, $membershipId, $postHash) ||
                $this->existingReply($noteId, $membershipId)) {
                return (object) ['claimed' => false];
            }
            $data = $source->getData();
            if (isset($data->opportunityAiExecutions->{$membershipId})) {
                return (object) ['claimed' => false];
            }
            $reply = $this->entityManager->getNewEntity('Note');
            $reply->set([
                'type' => Note::TYPE_POST, 'parentType' => 'Opportunity',
                'parentId' => $source->getParentId(), 'isInternal' => true,
                'createdById' => $this->user->getId(),
            ]);
            if (!$this->acl->check($reply, 'create')) {
                throw new Forbidden('No permission to report results in this opportunity stream.');
            }
            $data->opportunityAiExecutions ??= (object) [];
            $data->opportunityAiExecutions->{$membershipId} = $runId;
            $source->setData($data);
            $this->entityManager->saveEntity($source);
            return (object) ['claimed' => true];
        });
    }

    private function validate(Note $note, string $membershipId, string $postHash): ?object
    {
        if ($note->getType() !== Note::TYPE_POST || $note->getParentType() !== 'Opportunity' ||
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
        $opportunity = $this->entityManager->getEntityById('Opportunity', $note->getParentId());
        if (!$account || !$opportunity || $account->get('tenantId') !== $target->crmTenantId ||
            $opportunity->get('tenantId') !== $target->crmTenantId ||
            $chatwootUser->get('platformId') !== $account->get('platformId')) {
            return null;
        }
        if (!$this->access->canReadNote($this->user, $note) || !$this->acl->check($note, 'read')) {
            throw new Forbidden('No permission to read this opportunity stream.');
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

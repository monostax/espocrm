<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Services;

use DateTimeImmutable;
use DateTimeZone;
use Espo\Core\Acl;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Utils\Log;
use Espo\Entities\User;
use Espo\Modules\Global\Tools\Opportunity\StageRequirements;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Throwable;

/**
 * What happens around a deal's AI follow-up that people must see, or that changes what comes next (2026-11-23):
 * - every AI follow-up sent leaves a pill on the deal's mural ("Follow-up enviado por <IA>"), saying when it was the
 *   last attempt, so a "follow-up agendado" post is never left looking like a promise nobody kept;
 * - when the customer replies, the "no reply" task the backend makes after the last attempt is cancelled, and the
 *   consecutive attempt count goes back to 0 so the AI may follow up again.
 */
class OpportunityFollowupEvents
{
    public const TYPE = 'OpportunityFollowupSent';
    /** aiTaskKey prefix of the person's task made when the last follow-up went out (watcher run id `followup-no-reply:…`). */
    public const NO_REPLY_TASK_PREFIX = 'watcher:followup-no-reply:';
    /** An older message is history being synced, not the customer answering now. */
    private const LIVE_REPLY_SECONDS = 1800;
    private const PENDING_TASK = ['Planned', 'Started'];

    public function __construct(
        private EntityManager $entityManager,
        private Acl $acl,
        private User $user,
        private Log $log,
    ) {}

    /**
     * Records one sent follow-up, as the AI's own CRM user, once per Chat message. Whether it was the last attempt is
     * read from the deal: the send's bookkeeping already ran, and no timer left means no further attempt.
     */
    public function sent(string $id, int $accountId, int $conversationId, int $messageId): object
    {
        $deal = $this->entityManager->getEntityById('Opportunity', $id);
        if (!$deal) throw new NotFound();
        if (!$this->acl->checkEntityRead($deal) || !$this->acl->checkEntityStream($deal)) {
            throw new Forbidden('No access to this opportunity.');
        }
        $key = 'fs:' . $id . ':' . $messageId;
        $existing = $this->entityManager->getRDBRepository('Note')->where(['opportunityStreamEventKey' => $key])->findOne();
        if ($existing) return (object) ['id' => $existing->getId(), 'last' => (bool) ($existing->get('data')->last ?? false)];

        $last = !$deal->get('followupTimer');
        $agentId = $deal->get('followupAiAgentId');
        $agent = $agentId ? $this->entityManager->getEntityById('ChatwootAccountUserMembership', $agentId) : null;
        $data = [
            'aiAgentId' => $agent?->getId(),
            'aiAgentName' => $agent?->get('name'),
            'attempt' => (int) $deal->get('followupCount'),
            'last' => $last,
        ];
        // The pill links to the message only when the conversation is the deal's, in the deal's workspace.
        if ($this->linkedConversation($deal, $accountId, $conversationId)) {
            $data += ['chatwootAccountId' => $accountId, 'chatwootConversationId' => $conversationId, 'chatwootMessageId' => $messageId];
        }
        $note = $this->entityManager->createEntity('Note', [
            'type' => self::TYPE,
            'parentType' => 'Opportunity',
            'parentId' => $id,
            'isInternal' => true,
            'createdById' => $this->user->getId(),
            'opportunityStreamEventKey' => $key,
            'data' => (object) $data,
        ]);
        return (object) ['id' => $note->getId(), 'last' => $last];
    }

    /**
     * The customer wrote on a conversation of this deal (a new, public, incoming message). Never throws: the message
     * event that called it must stand.
     */
    public function customerReplied(Entity $deal, string $occurredAt): void
    {
        try {
            $at = new DateTimeImmutable($occurredAt, new DateTimeZone('UTC'));
            if ($at->getTimestamp() < time() - self::LIVE_REPLY_SECONDS) return;
            $this->entityManager->getTransactionManager()->run(function () use ($deal, $occurredAt): void {
                $current = $this->entityManager->getRDBRepository('Opportunity')
                    ->where(['id' => $deal->getId()])->forUpdate()->findOne();
                if (!$current || $current->get('status') !== 'Open') return;
                $this->cancelNoReplyTasks($current, $occurredAt);
                $this->resetAttempts($current->getId(), $occurredAt);
            });
        } catch (Throwable $e) {
            $this->log->warning('OpportunityFollowupEvents: reply on Opportunity/' . $deal->getId() . ' not applied: ' . $e->getMessage());
        }
    }

    /** "Cliente não respondeu ao follow-up" is no longer true: cancel it, and clear the step it held. */
    private function cancelNoReplyTasks(Entity $deal, string $occurredAt): void
    {
        $tasks = $this->entityManager->getRDBRepository('Task')->where([
            'parentType' => 'Opportunity',
            'parentId' => $deal->getId(),
            'status' => self::PENDING_TASK,
            'aiTaskKey*' => self::NO_REPLY_TASK_PREFIX . '%',
            'createdAt<' => $occurredAt,
        ])->find();
        $cancelled = [];
        foreach ($tasks as $task) {
            $task->set('status', 'Canceled');
            $this->entityManager->saveEntity($task);
            $cancelled[] = $task->getId();
        }
        if (!$cancelled) return;
        // Task hooks may have saved the deal: clear the step on a fresh copy, if it still points at a cancelled task.
        $fresh = $this->entityManager->getEntityById('Opportunity', $deal->getId());
        if (!$fresh || $fresh->get('nextActionType') !== 'Task' || !in_array($fresh->get('nextActionId'), $cancelled, true)) {
            return;
        }
        $fresh->set(['nextActionId' => null, 'nextActionType' => null]);
        $this->entityManager->saveEntity($fresh, [StageRequirements::BOOKKEEPING => true]);
    }

    /**
     * The attempt count is consecutive follow-ups without an answer. Active loops are reset by the backend on the
     * same message; this covers a loop that already stopped at its limit, unless a follow-up went out after the reply.
     */
    private function resetAttempts(string $id, string $occurredAt): void
    {
        $deal = $this->entityManager->getEntityById('Opportunity', $id);
        if (!$deal || $deal->get('followupStatus') === 'FollowupActive' || $deal->get('followupTimer') ||
            (int) $deal->get('followupCount') < 1) {
            return;
        }
        $laterSend = $this->entityManager->getRDBRepository('Note')->where([
            'type' => self::TYPE, 'parentType' => 'Opportunity', 'parentId' => $id, 'createdAt>' => $occurredAt,
        ])->findOne();
        if ($laterSend) return;
        $deal->set('followupCount', 0);
        $this->entityManager->saveEntity($deal, [StageRequirements::BOOKKEEPING => true]);
    }

    private function linkedConversation(Entity $deal, int $accountId, int $conversationId): bool
    {
        $account = $this->entityManager->getRDBRepository('ChatwootAccount')
            ->where(['chatwootAccountId' => $accountId, 'tenantId' => $deal->get('tenantId')])->findOne();
        if (!$account) return false;
        $conversation = $this->entityManager->getRDBRepository('ChatwootConversation')
            ->where(['chatwootAccountId' => $account->getId(), 'chatwootConversationId' => $conversationId])->findOne();
        if (!$conversation) return false;
        return (bool) $this->entityManager->getRDBRepository('ChatwootConversation')
            ->getRelation($conversation, 'opportunities')->where(['id' => $deal->getId()])->findOne();
    }
}

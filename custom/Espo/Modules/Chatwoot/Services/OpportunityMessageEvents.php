<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Services;

use DateTimeImmutable;
use DateTimeZone;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Metadata;
use Espo\Modules\Chatwoot\Tools\Stream\MuralConversationLinks;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Query\SelectBuilder;
use Espo\ORM\Query\UnionBuilder;

/** Durable message events. Grouping belongs to the UI, never to the read cursor. */
class OpportunityMessageEvents
{
    public const TYPE = OpportunityStreamEvents::MESSAGE_RECEIVED;
    public const STARTED_AT = 'opportunityMessageEventsStartedAt';
    private const KEY_PREFIX = ['Account' => 'am', 'Initiative' => 'im', 'Task' => 'tm'];

    public function __construct(
        private EntityManager $entityManager,
        private Config $config,
        private OpportunityStreamEvents $events,
        private OpportunityFollowupEvents $followups,
        private Metadata $metadata,
    ) {}

    public function recordWebhook(object $payload, string $accountId): void
    {
        if (!in_array($payload->message_type ?? null, [0, 'incoming'], true) ||
            ($payload->private ?? false)) {
            return;
        }

        // The webhook conversation ID is a display ID, scoped to the authenticated account.
        $displayId = $payload->conversation->display_id ?? $payload->conversation->id ?? null;
        if (!$displayId || !isset($payload->id, $payload->created_at)) {
            return;
        }

        $conversation = $this->entityManager->getRDBRepository('ChatwootConversation')->where([
            'chatwootAccountId' => $accountId,
            'chatwootConversationId' => (int) $displayId,
        ])->findOne();

        // A conversation not synced yet is picked up by the reconciliation path.
        if ($conversation) {
            $this->record($conversation, (string) $payload->id, $payload->created_at);
        }
    }

    public function recordSyncedMessage(Entity $message, Entity $conversation): void
    {
        if ($message->get('messageType') !== 'incoming' || $message->get('isPrivate')) {
            $this->removeIncorrectEvents($message, $conversation);
            return;
        }

        $this->record($conversation, (string) $message->get('chatwootMessageId'), $message->get('chatwootCreatedAt'));
    }

    /** Reconcile events created when an outgoing message was incorrectly synced as incoming. */
    private function removeIncorrectEvents(Entity $message, Entity $conversation): void
    {
        $messageId = (string) $message->get('chatwootMessageId');
        if (!ctype_digit($messageId) || (int) $messageId < 1) {
            return;
        }

        $opportunities = $this->entityManager->getRDBRepository('ChatwootConversation')->getRelation($conversation, 'opportunities')->find();
        // Indexed prefixes still find mural copies after a relationship has been removed.
        $matches = [];
        foreach (self::KEY_PREFIX as $type => $prefix) {
            $matches[] = ['parentType' => $type, 'opportunityStreamEventKey*' => "$prefix:$messageId:%"];
        }
        foreach ($opportunities as $opportunity) {
            $matches[] = ['opportunityStreamEventKey' => $this->messageKey($opportunity, $conversation, $messageId)];
        }

        $notes = $this->entityManager->getRDBRepository('Note')->where([
            'type' => self::TYPE,
            'relatedType' => 'ChatwootConversation',
            'relatedId' => $conversation->getId(),
            'OR' => $matches,
        ])->find();
        foreach ($notes as $note) {
            $this->entityManager->removeEntity($note);
        }
    }

    private function record(Entity $conversation, string $messageId, mixed $timestamp): void
    {
        $startedAt = $this->config->get(self::STARTED_AT);
        if (!$startedAt || !$timestamp || !ctype_digit($messageId) || (int) $messageId < 1) {
            return;
        }

        $occurredAt = is_numeric($timestamp)
            ? gmdate('Y-m-d H:i:s', (int) $timestamp)
            : (new DateTimeImmutable((string) $timestamp, new DateTimeZone('UTC')))
                ->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');

        // Do not turn a first sync of historical conversations into new unread activity.
        if ($occurredAt < $startedAt) {
            return;
        }

        $account = $this->entityManager->getEntityById('ChatwootAccount', $conversation->get('chatwootAccountId'));
        if (!$account || !$account->get('tenantId') || !$account->get('chatwootAccountId')) {
            return;
        }

        foreach ($this->parents($conversation, $account, $occurredAt) as $parent) {
            $tenanted = MuralConversationLinks::tenanted($parent->getEntityType());
            if ($tenanted && $parent->get('tenantId') !== $account->get('tenantId')) {
                continue;
            }

            $key = $this->messageKey($parent, $conversation, $messageId);
            $written = $this->events->write(self::TYPE, $parent, $conversation, [
                'chatwootAccountId' => (int) $account->get('chatwootAccountId'),
                'chatwootConversationId' => (int) $conversation->get('chatwootConversationId'),
                'chatwootMessageId' => (int) $messageId,
                'occurredAt' => $occurredAt,
            ], $key, $account->getLinkMultipleIdList('teams'));
            // Once per message: the customer answered, so a "no reply" step and the attempt count no longer hold.
            if ($written && $parent->getEntityType() === 'Opportunity') {
                $this->followups->customerReplied($parent, $occurredAt);
            }
        }
    }

    private function messageKey(Entity $parent, Entity $conversation, string $messageId): string
    {
        $hash = hash('sha256', implode(':', [$parent->getId(), $conversation->get('chatwootAccountId'), $messageId]));
        $prefix = self::KEY_PREFIX[$parent->getEntityType()] ?? null;
        return $prefix ? "$prefix:$messageId:" . substr($hash, 0, 40) : $hash;
    }

    private function parents(Entity $conversation, Entity $workspace, string $occurredAt): iterable
    {
        yield from $this->entityManager->getRDBRepository('ChatwootConversation')->getRelation($conversation, 'opportunities')->find();
        foreach (OpportunityStreamEvents::MESSAGE_ROLLOUT as $type => $setting) {
            $startedAt = $this->config->get($setting);
            if (!$startedAt || $occurredAt < $startedAt) continue;
            $union = UnionBuilder::create();
            // Every path joins the conversation's workspace, bound to the parent's tenant where the parent has one.
            foreach (MuralConversationLinks::paths($type) as $link) $union->query($link->where([
                'muralWorkspace.tenantId' => $workspace->get('tenantId'), 'muralConversation.id' => $conversation->getId(),
            ])->build());
            // Only open tasks: a finished task no longer turns unread when its customer writes (2026-11-26).
            $open = $type === 'Task' ? ['OR' => [['status!=' => $this->finishedTaskStatuses()], ['status' => null]]] : [];
            yield from $this->entityManager->getRDBRepository($type)->where([
                'id=s' => SelectBuilder::create()->fromQuery($union->build(), 'linkedParents')->select('id')->build(),
            ] + $open)->find();
        }
    }

    /** The Tasks list's Finished filter: completed and canceled. */
    private function finishedTaskStatuses(): array
    {
        return array_values(array_unique(array_merge(
            $this->metadata->get(['scopes', 'Task', 'completedStatusList']) ?? ['Completed'],
            $this->metadata->get(['scopes', 'Task', 'canceledStatusList']) ?? ['Canceled'],
        )));
    }
}

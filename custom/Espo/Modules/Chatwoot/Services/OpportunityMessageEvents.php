<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Services;

use DateTimeImmutable;
use DateTimeZone;
use Espo\Core\Utils\Config;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/** Durable message events. Grouping belongs to the UI, never to the read cursor. */
class OpportunityMessageEvents
{
    public const TYPE = OpportunityStreamEvents::MESSAGE_RECEIVED;
    public const STARTED_AT = 'opportunityMessageEventsStartedAt';

    public function __construct(
        private EntityManager $entityManager,
        private Config $config,
        private OpportunityStreamEvents $events,
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
            return;
        }

        $this->record($conversation, (string) $message->get('chatwootMessageId'), $message->get('chatwootCreatedAt'));
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

        $opportunities = $this->entityManager->getRDBRepository('ChatwootConversation')
            ->getRelation($conversation, 'opportunities')->find();

        foreach ($opportunities as $opportunity) {
            if ($opportunity->get('tenantId') !== $account->get('tenantId')) {
                continue;
            }

            $key = hash('sha256', implode(':', [$opportunity->getId(), $account->getId(), $messageId]));
            $this->events->write(self::TYPE, $opportunity, $conversation, [
                'chatwootAccountId' => (int) $account->get('chatwootAccountId'),
                'chatwootConversationId' => (int) $conversation->get('chatwootConversationId'),
                'chatwootMessageId' => (int) $messageId,
                'occurredAt' => $occurredAt,
            ], $key, $account->getLinkMultipleIdList('teams'));
        }
    }
}

<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureJourney\Services;

use Espo\Modules\Chatwoot\Services\ChatwootApiClient;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use RuntimeException;

/** Recover replies hidden by the conversation API's last-message-only snapshot. */
class JourneyWhatsAppReplyReconciler
{
    public function __construct(
        private EntityManager $entityManager,
        private ChatwootApiClient $api,
        private JourneyWhatsAppReplyTracker $tracker,
        private TenantResolver $tenants,
    ) {}

    /** @return list<array<string, mixed>> Messages for the normal CRM message importer. */
    public function reconcile(Entity $conversation): array
    {
        if (!in_array($conversation->get('inboxChannelType'),
            ['whatsappQrcode', 'whatsappCloudApi', 'whatsappCoexistence'], true)) {
            return [];
        }
        $account = $this->entityManager->getEntityById('ChatwootAccount', (string) $conversation->get('chatwootAccountId'));
        $tenantId = $account ? $this->tenants->resolveTenantIdForEntity($account) : null;
        if (!$tenantId || $this->tenants->resolveTenantIdForEntity($conversation) !== $tenantId) {
            return [];
        }
        $opportunities = $this->tracker->getLinkedOpportunities($conversation, $tenantId, gmdate('Y-m-d H:i:s'));
        $targets = array_map(static fn (Entity $entity): string => $entity->getId(), $opportunities);
        $where = [];
        if ($targets !== []) {
            $where[] = ['targetType' => 'Opportunity', 'targetId' => $targets];
        }
        if ($conversation->get('journeyRecordId')) {
            $where[] = ['id' => $conversation->get('journeyRecordId')];
        }
        // Only active enrollments require transcript polling, not every CRM conversation.
        if ($where === []) {
            return [];
        }
        $since = null;
        foreach ($this->entityManager->getRDBRepository('JourneyRecord')->where([
            'tenantId' => $tenantId, 'status' => 'Active', 'OR' => $where,
        ])->find() as $record) {
            $journey = $this->entityManager->getEntityById('Journey', (string) $record->get('journeyId'));
            if (!$journey || $journey->get('status') !== 'Active' || $journey->get('tenantId') !== $tenantId) {
                continue;
            }
            $created = $record->get('createdAt');
            if ($created && ($since === null || $created < $since)) {
                $since = $created;
            }
        }
        if ($since === null) {
            return [];
        }
        $platform = $this->entityManager->getEntityById('ChatwootPlatform', (string) $account->get('platformId'));
        if (!$platform || !$platform->get('backendUrl') || !$account->get('apiKey')) {
            throw new RuntimeException('Journey reply reconciliation requires Chatwoot credentials.');
        }
        $cutoff = (new \DateTimeImmutable($since, new \DateTimeZone('UTC')))->getTimestamp();
        $before = null;
        $messages = [];
        do {
            $page = $this->api->getConversationMessages(
                $platform->get('backendUrl'), $account->get('apiKey'),
                (int) $account->get('chatwootAccountId'), (int) $conversation->get('chatwootConversationId'), $before,
            );
            $next = null;
            $reachedEnrollment = false;
            foreach ($page as $message) {
                $id = (int) ($message['id'] ?? 0);
                $createdAt = $message['created_at'] ?? null;
                if ($id <= 0 || $createdAt === null) {
                    throw new RuntimeException('Invalid Chatwoot message during journey reply reconciliation.');
                }
                $next = $next === null ? $id : min($next, $id);
                $timestamp = is_numeric($createdAt) ? (int) $createdAt
                    : (new \DateTimeImmutable((string) $createdAt, new \DateTimeZone('UTC')))->getTimestamp();
                if ($timestamp < $cutoff) {
                    $reachedEnrollment = true;
                    continue;
                }
                $messages[$id] = $message;
            }
            if ($page === [] || $reachedEnrollment) {
                break;
            }
            if ($next === null || ($before !== null && $next >= $before)) {
                throw new RuntimeException('Chatwoot message pagination did not advance.');
            }
            $before = $next;
        } while (true);

        // Replay oldest first, preserving the actual reply timestamp. The tracker
        // deduplicates webhook/sync/recovery and rejects pre-enrollment replies.
        ksort($messages, SORT_NUMERIC);
        foreach ($messages as $message) {
            if (!in_array($message['message_type'] ?? null, [0, '0', 'incoming'], true) || !empty($message['private'])) {
                continue;
            }
            $this->tracker->handleWebhookIncoming([
                'chatwootConversationId' => (string) $conversation->get('chatwootConversationId'),
                'espoAccountId' => $account->getId(),
                'chatwootMessageId' => $message['id'],
                'createdAt' => $message['created_at'],
                'content' => $message['content'] ?? null,
            ]);
        }

        return array_values($messages);
    }
}

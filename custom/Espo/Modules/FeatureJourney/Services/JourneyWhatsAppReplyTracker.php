<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureJourney\Services;

use Espo\Core\InjectableFactory;
use Espo\Core\ORM\Repository\Option\SaveOption;
use Espo\Core\Utils\Log;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Throwable;

/**
 * Shared emitter for journey signal `whatsapp_replied`.
 *
 * Correlation: JourneyRecord stamped with last outbound conversation
 * (whatsAppChatwootConversationId + whatsAppChatwootAccountId), and/or
 * ChatwootConversation.journeyRecordId / journeyId.
 *
 * Call sites:
 *  - FeatureJourney hook on ChatwootMessage afterSave (sync path)
 *  - Chatwoot DeliveryWebhook message_created (real-time path)
 */
class JourneyWhatsAppReplyTracker
{
    private const RECORDER_FQCN = 'Espo\\Modules\\FeatureTrackingEvent\\Services\\InternalEventRecorder';

    /** @var array<string, true> */
    private static array $fired = [];

    public function __construct(
        private EntityManager $entityManager,
        private JourneySignalDispatcher $dispatcher,
        private TenantResolver $tenantResolver,
        private InjectableFactory $injectableFactory,
        private Log $log,
    ) {}

    /**
     * ChatwootMessage afterSave path.
     */
    public function handleChatwootMessage(Entity $message): void
    {
        if ($message->getEntityType() !== 'ChatwootMessage') {
            return;
        }

        if ((string) ($message->get('messageType') ?? '') !== 'incoming' || $message->get('isPrivate')) {
            return;
        }

        if (!$message->isNew() && !$message->isAttributeChanged('messageType')) {
            return;
        }

        $messageId = $message->hasId() ? (string) $message->getId() : '';
        $espoAccountId = (string) ($message->get('chatwootAccountId') ?? '');
        $chatwootMessageId = $message->get('chatwootMessageId');

        $dedupeKeys = [];
        if ($messageId !== '') {
            $dedupeKeys[] = 'msg:' . $messageId;
        }
        if ($espoAccountId !== '' && $chatwootMessageId !== null && $chatwootMessageId !== '') {
            $dedupeKeys[] = 'cwmsg:' . $espoAccountId . ':' . (string) $chatwootMessageId;
        }

        if ($dedupeKeys === []) {
            return;
        }

        foreach ($dedupeKeys as $key) {
            if (isset(self::$fired[$key])) {
                return;
            }
        }

        $conversationCrmId = (string) ($message->get('conversationId') ?? '');

        $conversation = null;
        if ($conversationCrmId !== '') {
            $conversation = $this->entityManager->getEntityById('ChatwootConversation', $conversationCrmId);
        }

        $externalConversationId = '';
        if ($conversation) {
            $externalConversationId = (string) ($conversation->get('chatwootConversationId') ?? '');
            if ($espoAccountId === '') {
                $espoAccountId = (string) ($conversation->get('chatwootAccountId') ?? '');
            }
        }

        if ($externalConversationId === '' || $espoAccountId === '') {
            return;
        }

        $this->dispatchLinkedOpportunities(
            $conversation, $espoAccountId, $chatwootMessageId, $message->get('chatwootCreatedAt'),
        );

        // Cross-path de-dupe with webhook keys.
        $whKey = 'wh:' . $espoAccountId . ':' . $externalConversationId . ':' .
            (string) ($chatwootMessageId ?? '');
        if (isset(self::$fired[$whKey])) {
            return;
        }

        $journeyId = $conversation
            ? (string) ($conversation->get('journeyId') ?? '')
            : '';
        $journeyRecordId = $conversation
            ? (string) ($conversation->get('journeyRecordId') ?? '')
            : '';

        [$targetType, $targetId, $contactId, $journeyId, $journeyRecordId] = $this->resolveTargetAndJourney(
            $message,
            $conversation,
            $externalConversationId,
            $espoAccountId,
            $journeyId,
            $journeyRecordId,
        );

        if ($targetType === null || $targetId === null) {
            return;
        }

        // Require journey trail (like email token) — not every inbound WA.
        if ($journeyId === '' && $journeyRecordId === '') {
            return;
        }

        foreach ($dedupeKeys as $key) {
            self::$fired[$key] = true;
        }
        self::$fired[$whKey] = true;

        $content = $message->get('content');
        $contentPreview = is_string($content) ? mb_substr(strip_tags($content), 0, 200) : null;

        $properties = [
            'messageId' => $messageId !== '' ? $messageId : null,
            'chatwootMessageId' => $chatwootMessageId,
            'conversationId' => $conversationCrmId !== '' ? $conversationCrmId : null,
            'chatwootConversationId' => $externalConversationId,
            'contentPreview' => $contentPreview,
            'inboxId' => $conversation ? ($conversation->get('inboxId') ?? null) : null,
            'channelType' => $conversation
                ? ($conversation->get('inboxChannelType') ?? null)
                : null,
            'journeyId' => $journeyId !== '' ? $journeyId : null,
            'journeyRecordId' => $journeyRecordId !== '' ? $journeyRecordId : null,
            'targetType' => $targetType,
            'targetId' => $targetId,
        ];

        $this->emit(
            $targetType,
            $targetId,
            $contactId,
            $properties,
            $contentPreview,
            $message,
            $conversation,
        );
    }

    /**
     * DeliveryWebhook real-time path (before CRM message row may exist).
     *
     * @param array{
     *   chatwootConversationId: string,
     *   espoAccountId: string,
     *   chatwootMessageId?: string|int|null,
     *   content?: ?string,
     * } $payload
     */
    public function handleWebhookIncoming(array $payload): void
    {
        if (!empty($payload['isPrivate'])) {
            return;
        }
        $externalConversationId = trim((string) ($payload['chatwootConversationId'] ?? ''));
        $espoAccountId = trim((string) ($payload['espoAccountId'] ?? ''));

        if ($externalConversationId === '' || $espoAccountId === '') {
            return;
        }

        $chatwootMessageId = $payload['chatwootMessageId'] ?? null;
        $dedupeKey = 'wh:' . $espoAccountId . ':' . $externalConversationId . ':' .
            (string) ($chatwootMessageId ?? '');

        if (isset(self::$fired[$dedupeKey])) {
            return;
        }

        $conversation = $this->entityManager
            ->getRDBRepository('ChatwootConversation')
            ->where([
                'chatwootConversationId' => (int) $externalConversationId,
                'chatwootAccountId' => $espoAccountId,
            ])
            ->findOne();

        $this->dispatchLinkedOpportunities(
            $conversation, $espoAccountId, $chatwootMessageId, $payload['createdAt'] ?? null,
        );

        $journeyId = $conversation
            ? (string) ($conversation->get('journeyId') ?? '')
            : '';
        $journeyRecordId = $conversation
            ? (string) ($conversation->get('journeyRecordId') ?? '')
            : '';

        [$targetType, $targetId, $contactId, $journeyId, $journeyRecordId] = $this->resolveTargetAndJourney(
            null,
            $conversation,
            $externalConversationId,
            $espoAccountId,
            $journeyId,
            $journeyRecordId,
        );

        if ($targetType === null || $targetId === null) {
            return;
        }

        if ($journeyId === '' && $journeyRecordId === '') {
            return;
        }

        self::$fired[$dedupeKey] = true;

        // Also mark msg-key when sync later creates the ChatwootMessage with same external id.
        if ($chatwootMessageId !== null && $chatwootMessageId !== '') {
            self::$fired['cwmsg:' . $espoAccountId . ':' . (string) $chatwootMessageId] = true;
        }

        $content = $payload['content'] ?? null;
        $contentPreview = is_string($content) ? mb_substr(strip_tags($content), 0, 200) : null;

        $properties = [
            'messageId' => null,
            'chatwootMessageId' => $chatwootMessageId,
            'conversationId' => $conversation?->getId(),
            'chatwootConversationId' => $externalConversationId,
            'contentPreview' => $contentPreview,
            'inboxId' => $conversation ? ($conversation->get('inboxId') ?? null) : null,
            'channelType' => $conversation
                ? ($conversation->get('inboxChannelType') ?? null)
                : null,
            'journeyId' => $journeyId !== '' ? $journeyId : null,
            'journeyRecordId' => $journeyRecordId !== '' ? $journeyRecordId : null,
            'targetType' => $targetType,
            'targetId' => $targetId,
        ];

        $this->emit(
            $targetType,
            $targetId,
            $contactId,
            $properties,
            $contentPreview,
            null,
            $conversation,
        );
    }

    /** Replies to manually sent messages: correlate only explicitly linked Opportunities. */
    private function dispatchLinkedOpportunities(
        ?Entity $conversation,
        string $accountId,
        mixed $messageId,
        mixed $createdAt,
    ): void {
        if (!$conversation || (string) $conversation->get('chatwootAccountId') !== $accountId ||
            $messageId === null || $createdAt === null || $createdAt === '') {
            return;
        }

        $inbox = $conversation->get('inboxId')
            ? $this->entityManager->getEntityById('ChatwootInbox', (string) $conversation->get('inboxId'))
            : null;
        $channel = $inbox?->get('channelType') ?: $conversation->get('inboxChannelType');
        if (!in_array($channel, ['whatsappQrcode', 'whatsappCloudApi', 'whatsappCoexistence'], true)) {
            return;
        }
        if ($inbox && (string) $inbox->get('chatwootAccountId') !== $accountId) {
            return;
        }

        $account = $this->entityManager->getEntityById('ChatwootAccount', $accountId);
        $tenantId = $account ? $this->tenantResolver->resolveTenantIdForEntity($account) : null;
        if (!$tenantId || $this->tenantResolver->resolveTenantIdForEntity($conversation) !== $tenantId) {
            return;
        }

        try {
            $timestamp = is_numeric($createdAt)
                ? (int) $createdAt
                : (new \DateTimeImmutable((string) $createdAt, new \DateTimeZone('UTC')))->getTimestamp();
        } catch (Throwable) {
            return;
        }
        // Reject malformed/future timestamps and old messages synced after enrollment.
        if ($timestamp <= 0 || $timestamp > time() + 300) {
            return;
        }
        $occurredAt = gmdate('Y-m-d H:i:s', $timestamp);
        $opportunities = $this->entityManager->getRDBRepository('ChatwootConversation')
            ->getRelation($conversation, 'opportunities')->find();
        foreach ($opportunities as $opportunity) {
            if ($this->tenantResolver->resolveTenantIdForEntity($opportunity) !== $tenantId) {
                continue;
            }
            $records = $this->entityManager->getRDBRepository('JourneyRecord')->where([
                'tenantId' => $tenantId,
                'targetType' => 'Opportunity',
                'targetId' => $opportunity->getId(),
                'status' => 'Active',
                'createdAt<=' => $occurredAt,
            ])->find();
            foreach ($records as $record) {
                $journey = $this->entityManager->getEntityById('Journey', (string) $record->get('journeyId'));
                if (!$journey || $journey->get('status') !== 'Active' ||
                    (string) $journey->get('tenantId') !== $tenantId) {
                    continue;
                }
                $key = 'opportunity:' . $accountId . ':' . $messageId . ':' . $record->getId();
                if (isset(self::$fired[$key])) {
                    continue;
                }
                // Durable evidence also blocks a due timer before its exit signal job runs.
                if (!$record->get('whatsAppRepliedAt')) {
                    $record->set('whatsAppRepliedAt', $occurredAt);
                    $this->entityManager->saveEntity($record, [SaveOption::SILENT => true]);
                }
                $this->emit('Opportunity', (string) $opportunity->getId(), null, [
                    'journeyId' => $journey->getId(),
                    'journeyRecordId' => $record->getId(),
                    'conversationId' => $conversation->getId(),
                    'chatwootConversationId' => $conversation->get('chatwootConversationId'),
                    'chatwootMessageId' => $messageId,
                    'occurredAt' => $occurredAt,
                    'channelType' => $channel,
                ], null, null, $conversation);
                self::$fired[$key] = true;
            }
        }
    }

    /**
     * @return array{0: ?string, 1: ?string, 2: ?string, 3: string, 4: string}
     */
    private function resolveTargetAndJourney(
        ?Entity $message,
        ?Entity $conversation,
        string $externalConversationId,
        string $espoAccountId,
        string $journeyId,
        string $journeyRecordId,
    ): array {
        // 1) Prefer stamped JourneyRecord on conversation.
        if ($journeyRecordId !== '') {
            $record = $this->entityManager->getEntityById('JourneyRecord', $journeyRecordId);
            if ($record) {
                $tType = (string) ($record->get('targetType') ?? '');
                $tId = (string) ($record->get('targetId') ?? '');
                $jId = (string) ($record->get('journeyId') ?? '');
                if ($tType !== '' && $tId !== '') {
                    return [
                        $tType,
                        $tId,
                        $tType === 'Contact' ? $tId : null,
                        $jId !== '' ? $jId : $journeyId,
                        $journeyRecordId,
                    ];
                }
            }
        }

        // 2) Match Active JourneyRecord by conversation trail from outbound send.
        $matched = $this->entityManager
            ->getRDBRepository('JourneyRecord')
            ->where([
                'whatsAppChatwootConversationId' => $externalConversationId,
                'whatsAppChatwootAccountId' => $espoAccountId,
                'status' => ['Active', 'Processing'],
            ])
            ->order('modifiedAt', 'DESC')
            ->findOne();

        if ($matched) {
            $tType = (string) ($matched->get('targetType') ?? '');
            $tId = (string) ($matched->get('targetId') ?? '');
            $jId = (string) ($matched->get('journeyId') ?? '');
            $rId = (string) $matched->getId();
            if ($tType !== '' && $tId !== '') {
                return [
                    $tType,
                    $tId,
                    $tType === 'Contact' ? $tId : null,
                    $jId,
                    $rId,
                ];
            }
        }

        // Also try integer string variants (id vs (string)id).
        if (ctype_digit($externalConversationId)) {
            $alts = array_unique([
                (string) ((int) $externalConversationId),
                $externalConversationId,
            ]);
            foreach ($alts as $alt) {
                if ($alt === $externalConversationId) {
                    continue;
                }
                $matched = $this->entityManager
                    ->getRDBRepository('JourneyRecord')
                    ->where([
                        'whatsAppChatwootConversationId' => $alt,
                        'whatsAppChatwootAccountId' => $espoAccountId,
                        'status' => ['Active', 'Processing'],
                    ])
                    ->order('modifiedAt', 'DESC')
                    ->findOne();
                if ($matched) {
                    $tType = (string) ($matched->get('targetType') ?? '');
                    $tId = (string) ($matched->get('targetId') ?? '');
                    $jId = (string) ($matched->get('journeyId') ?? '');
                    $rId = (string) $matched->getId();
                    if ($tType !== '' && $tId !== '') {
                        return [
                            $tType,
                            $tId,
                            $tType === 'Contact' ? $tId : null,
                            $jId,
                            $rId,
                        ];
                    }
                }
            }
        }

        // 3) Fallback: CRM Contact linked on message/conversation (but only if journey already resolved).
        $contactId = null;
        if ($message) {
            $contactId = $message->get('contactId') ? (string) $message->get('contactId') : null;
        }
        if (!$contactId && $conversation) {
            $contactId = $conversation->get('contactId') ? (string) $conversation->get('contactId') : null;
        }

        if ($contactId && ($journeyId !== '' || $journeyRecordId !== '')) {
            return ['Contact', $contactId, $contactId, $journeyId, $journeyRecordId];
        }

        return [null, null, null, $journeyId, $journeyRecordId];
    }

    /**
     * @param array<string, mixed> $properties
     */
    private function emit(
        string $targetType,
        string $targetId,
        ?string $contactId,
        array $properties,
        ?string $detail,
        ?Entity $message,
        ?Entity $conversation,
    ): void {
        $target = $this->entityManager->getEntityById($targetType, $targetId);
        if (!$target) {
            return;
        }

        $tenantId = null;
        if ($conversation) {
            $tenantId = $this->tenantResolver->resolveTenantIdForEntity($conversation);
        }
        if (!$tenantId && $message) {
            $tenantId = $this->tenantResolver->resolveTenantIdForEntity($message);
        }
        if (!$tenantId) {
            $tenantId = $this->tenantResolver->resolveTenantIdForEntity($target);
        }

        $recordId = isset($properties['journeyRecordId']) && is_string($properties['journeyRecordId'])
            ? $properties['journeyRecordId']
            : '';
        if (!$tenantId && $recordId !== '') {
            $record = $this->entityManager->getEntityById('JourneyRecord', $recordId);
            if ($record) {
                $tenantId = $this->tenantResolver->resolveTenantIdForEntity($record);
            }
        }

        if (!$tenantId) {
            $this->log->info(
                'JourneyWhatsAppReplyTracker: no tenant for ' .
                $targetType . '/' . $targetId . '; skip.'
            );

            return;
        }

        $cwMessageId = $properties['chatwootMessageId'] ?? null;
        $cwAccountId = null;
        if ($message) {
            $cwAccountId = $message->get('chatwootAccountId')
                ? (string) $message->get('chatwootAccountId')
                : null;
        }
        if (!$cwAccountId && $conversation) {
            $cwAccountId = $conversation->get('chatwootAccountId')
                ? (string) $conversation->get('chatwootAccountId')
                : null;
        }

        if ($this->alreadyEmitted($tenantId, $targetType, $targetId, $recordId, $cwMessageId, $cwAccountId)) {
            return;
        }

        $eventId = $this->tryRecordTracking(
            $tenantId,
            $contactId,
            $targetType,
            $targetId,
            $properties,
            $detail,
        );

        $this->markEmitted($recordId, $cwMessageId);

        if ($eventId === null) {
            $this->dispatcher->dispatch(
                $tenantId,
                JourneyWhatsAppSignal::CODE_REPLIED,
                $target,
                $properties,
                null,
            );
        }
    }

    /**
     * Cross-request de-dupe (webhook then ChatwootMessage sync).
     */
    private function alreadyEmitted(
        string $tenantId,
        string $parentType,
        string $parentId,
        string $journeyRecordId,
        mixed $chatwootMessageId,
        ?string $espoAccountId,
    ): bool {
        if ($chatwootMessageId === null || $chatwootMessageId === '') {
            return false;
        }

        $msgKey = (string) $chatwootMessageId;

        if ($journeyRecordId !== '') {
            $record = $this->entityManager->getEntityById('JourneyRecord', $journeyRecordId);
            if ($record) {
                $last = (string) ($record->get('whatsAppLastReplyChatwootMessageId') ?? '');
                if ($last !== '' && $last === $msgKey) {
                    return true;
                }
            }
        }

        if (!class_exists('Espo\\Modules\\FeatureTrackingEvent\\Entities\\TrackingEvent')) {
            return false;
        }

        try {
            $since = gmdate('Y-m-d H:i:s', time() - 86400);
            $events = $this->entityManager
                ->getRDBRepository('TrackingEvent')
                ->where([
                    'tenantId' => $tenantId,
                    'code' => JourneyWhatsAppSignal::CODE_REPLIED,
                    'parentType' => $parentType,
                    'parentId' => $parentId,
                    'createdAt>=' => $since,
                ])
                ->order('createdAt', 'DESC')
                ->limit(0, 30)
                ->find();

            foreach ($events as $event) {
                $payload = $event->get('payload');
                if ($payload instanceof \stdClass) {
                    $payload = json_decode(json_encode($payload) ?: '{}', true) ?: [];
                }
                if (!is_array($payload)) {
                    continue;
                }
                $existing = $payload['chatwootMessageId'] ?? null;
                if ($existing !== null && (string) $existing === $msgKey) {
                    return true;
                }
            }
        } catch (Throwable $e) {
            $this->log->debug(
                'JourneyWhatsAppReplyTracker: de-dupe lookup failed: ' . $e->getMessage()
            );
        }

        return false;
    }

    private function markEmitted(string $journeyRecordId, mixed $chatwootMessageId): void
    {
        if ($journeyRecordId === '' || $chatwootMessageId === null || $chatwootMessageId === '') {
            return;
        }

        try {
            $record = $this->entityManager->getEntityById('JourneyRecord', $journeyRecordId);
            if (!$record) {
                return;
            }
            $record->set('whatsAppLastReplyChatwootMessageId', (string) $chatwootMessageId);
            $this->entityManager->saveEntity($record, [SaveOption::SILENT => true]);
        } catch (Throwable $e) {
            $this->log->debug(
                'JourneyWhatsAppReplyTracker: mark emitted failed: ' . $e->getMessage()
            );
        }
    }

    /**
     * @param array<string, mixed> $properties
     */
    private function tryRecordTracking(
        string $tenantId,
        ?string $contactId,
        string $parentType,
        string $parentId,
        array $properties,
        ?string $detail,
    ): ?string {
        if (!class_exists(self::RECORDER_FQCN)) {
            return null;
        }

        try {
            /** @var object $recorder */
            $recorder = $this->injectableFactory->create(self::RECORDER_FQCN);

            if (!method_exists($recorder, 'record')) {
                return null;
            }

            $id = $recorder->record($tenantId, JourneyWhatsAppSignal::CODE_REPLIED, [
                'contactId' => $contactId,
                'parentType' => $parentType,
                'parentId' => $parentId,
                'properties' => $properties,
                'detail' => $detail,
            ]);

            return is_string($id) && $id !== '' ? $id : null;
        } catch (Throwable $e) {
            $this->log->warning(
                'JourneyWhatsAppReplyTracker: tracking record failed: ' . $e->getMessage()
            );

            return null;
        }
    }
}

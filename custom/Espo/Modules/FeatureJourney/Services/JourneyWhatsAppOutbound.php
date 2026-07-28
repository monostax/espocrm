<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureJourney\Services;

use Espo\Core\Exceptions\Error;
use Espo\Core\Htmlizer\TemplateRendererFactory;
use Espo\Core\ORM\Repository\Option\SaveOption;
use Espo\Core\Utils\Log;
use Espo\Entities\PhoneNumber;
use Espo\Entities\User;
use Espo\Modules\Chatwoot\Services\ChatwootApiClient;
use Espo\Modules\Chatwoot\Tools\PhoneNormalizer;
use Espo\Modules\Global\Tools\CustomField\TemplateBridge;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\Repositories\PhoneNumber as PhoneNumberRepository;
use Throwable;

/**
 * Shared WhatsApp send path for journey/automation actions (Chatwoot-backed).
 *
 * Free-text (`sendWhatsAppMessage`) channels:
 *   - whatsappCloudApi
 *   - whatsappCoexistence
 *   - whatsappQrcode (WAHA)
 *
 * Templates (`sendWhatsAppTemplate`) are Meta-only:
 *   - whatsappCloudApi
 *   - whatsappCoexistence
 *
 * Credentials: ChatwootInbox → Account → Platform.
 * Free text → ChatwootApiClient::sendOutgoingMessage.
 * Templates → ChatwootApiClient::sendTemplateMessage.
 */
class JourneyWhatsAppOutbound
{
    /**
     * Free-text outbound allow-list (Cloud + Coexistence + QR/WAHA).
     *
     * @var list<string>
     */
    public const CHANNELS_MESSAGE = [
        'whatsappQrcode',
        'whatsappCloudApi',
        'whatsappCoexistence',
    ];

    /**
     * Meta template allow-list (no WAHA QR templates).
     *
     * @var list<string>
     */
    public const CHANNELS_TEMPLATE = [
        'whatsappCloudApi',
        'whatsappCoexistence',
    ];

    public function __construct(
        private EntityManager $entityManager,
        private ChatwootApiClient $chatwootApiClient,
        private TenantGuard $tenantGuard,
        private TemplateRendererFactory $templateRendererFactory,
        private TemplateBridge $templateBridge,
        private Log $log,
    ) {}

    /**
     * @param list<string> $allowedChannels
     * @return array{
     *   platformUrl: string,
     *   accountApiKey: string,
     *   externalAccountId: int,
     *   externalInboxId: int,
     *   channelType: string,
     *   inbox: Entity
     * }
     */
    public function resolveConnection(
        string $chatwootInboxId,
        string $tenantId,
        array $allowedChannels,
        ?User $actor = null,
    ): array {
        $inbox = $this->tenantGuard->assertChatwootInboxAllowedForSending(
            $chatwootInboxId,
            $tenantId,
            $allowedChannels,
            $actor,
        );

        $channelType = (string) ($inbox->get('channelType') ?? '');

        $chatwootAccountId = (string) ($inbox->get('chatwootAccountId') ?? '');
        if ($chatwootAccountId === '') {
            throw new Error('JourneyWhatsApp: inbox has no Chatwoot account.');
        }

        $account = $this->entityManager->getEntityById('ChatwootAccount', $chatwootAccountId);
        if (!$account) {
            throw new Error('JourneyWhatsApp: Chatwoot account not found.');
        }

        $platformId = (string) ($account->get('platformId') ?? '');
        $platform = $platformId !== ''
            ? $this->entityManager->getEntityById('ChatwootPlatform', $platformId)
            : null;

        if (!$platform) {
            throw new Error('JourneyWhatsApp: Chatwoot platform not found.');
        }

        $platformUrl = (string) ($platform->get('backendUrl') ?? '');
        $accountApiKey = (string) ($account->get('apiKey') ?? '');
        $externalAccountId = (int) ($account->get('chatwootAccountId') ?? 0);
        $externalInboxId = (int) ($inbox->get('chatwootInboxId') ?? 0);

        if ($platformUrl === '' || $accountApiKey === '' || $externalAccountId <= 0 || $externalInboxId <= 0) {
            throw new Error('JourneyWhatsApp: missing Chatwoot connection details (URL, API key, or ids).');
        }

        return [
            'platformUrl' => $platformUrl,
            'accountApiKey' => $accountApiKey,
            'externalAccountId' => $externalAccountId,
            'externalInboxId' => $externalInboxId,
            'channelType' => $channelType,
            'inbox' => $inbox,
        ];
    }

    /**
     * Single sendable phone (override, or first of {@see resolvePhones}).
     */
    public function resolvePhone(Entity $target, ?string $override = null): ?string
    {
        $phones = $this->resolvePhones($target, $override);

        return $phones[0] ?? null;
    }

    /**
     * All sendable phones on the target (primary + secondary).
     *
     * - Non-empty override → that number only (semicolon/comma/whitespace separated list allowed).
     * - Otherwise: phoneNumberData (skip opted-out / invalid / Fax), then primary field fallbacks,
     *   then ContactChannelIdentity whatsapp sourceIds when available.
     *
     * Matches WhatsAppCampaign multi-number enrollment semantics.
     *
     * @return list<string> E.164-normalized unique phones
     */
    public function resolvePhones(Entity $target, ?string $override = null): array
    {
        if (is_string($override) && trim($override) !== '') {
            return $this->normalizePhoneList($override);
        }

        $phones = [];
        $seen = [];

        $append = function (?string $raw) use (&$phones, &$seen): void {
            if ($raw === null || trim($raw) === '') {
                return;
            }
            $n = PhoneNormalizer::normalize(trim($raw));
            if ($n === null || isset($seen[$n])) {
                return;
            }
            $seen[$n] = true;
            $phones[] = $n;
        };

        if ($target->hasId()) {
            try {
                /** @var PhoneNumberRepository $repo */
                $repo = $this->entityManager->getRepository(PhoneNumber::ENTITY_TYPE);

                foreach ($repo->getPhoneNumberData($target) as $row) {
                    if (!empty($row->optOut) || !empty($row->invalid)) {
                        continue;
                    }
                    if (strcasecmp((string) ($row->type ?? ''), 'Fax') === 0) {
                        continue;
                    }
                    $append(isset($row->phoneNumber) ? (string) $row->phoneNumber : null);
                }
            } catch (Throwable $e) {
                $this->log->debug('JourneyWhatsApp: phoneNumberData: ' . $e->getMessage());
            }
        }

        foreach (['phoneNumber', 'phoneNumberMobile', 'mobilePhone'] as $field) {
            if (!$target->hasAttribute($field) && !$target->has($field)) {
                continue;
            }
            $raw = $target->get($field);
            if (is_string($raw)) {
                $append($raw);
            }
        }

        // WhatsApp channel identities (multi-number) when not yet mirrored to phoneNumber.
        if ($target->hasId() && $target->getEntityType() === 'Contact') {
            try {
                $identities = $this->entityManager
                    ->getRDBRepository('ContactChannelIdentity')
                    ->where([
                        'contactId' => $target->getId(),
                        'channelType' => 'whatsapp',
                    ])
                    ->select(['sourceId'])
                    ->find();

                foreach ($identities as $identity) {
                    $append((string) ($identity->get('sourceId') ?? ''));
                }
            } catch (Throwable $e) {
                $this->log->debug('JourneyWhatsApp: channel identities: ' . $e->getMessage());
            }
        }

        return $phones;
    }

    /**
     * @return list<string>
     */
    private function normalizePhoneList(string $raw): array
    {
        $parts = preg_split('/[\s,;]+/', $raw) ?: [];
        $phones = [];
        $seen = [];

        foreach ($parts as $part) {
            $part = trim((string) $part);
            if ($part === '') {
                continue;
            }
            $n = PhoneNormalizer::normalize($part);
            if ($n === null || isset($seen[$n])) {
                continue;
            }
            $seen[$n] = true;
            $phones[] = $n;
        }

        return $phones;
    }

    public function isOptedOut(Entity $target): bool
    {
        if ($target->getEntityType() !== 'Contact') {
            return false;
        }

        return (bool) $target->get('whatsAppOptedOut');
    }

    public function displayName(Entity $target): string
    {
        $name = $target->get('name');
        if (is_string($name) && trim($name) !== '') {
            return trim($name);
        }

        $first = (string) ($target->get('firstName') ?? '');
        $last = (string) ($target->get('lastName') ?? '');

        return trim($first . ' ' . $last);
    }

    /**
     * Optional journey correlation context for reply tracking (`whatsapp_replied`).
     *
     * @param array{
     *   journeyId?: string,
     *   journeyRecordId?: string
     * }|null $journeyContext
     * @param array<string, mixed> $conn from resolveConnection
     * @return array{message_id: mixed, conversation_id: mixed}
     */
    public function sendFreeText(
        array $conn,
        string $phone,
        string $name,
        string $body,
        ?array $journeyContext = null,
    ): array {
        $contact = $this->chatwootApiClient->findOrCreateContact(
            $conn['platformUrl'],
            $conn['accountApiKey'],
            $conn['externalAccountId'],
            $conn['externalInboxId'],
            $phone,
            $name !== '' ? $name : null,
        );

        $contactId = (int) ($contact['id'] ?? 0);
        if ($contactId <= 0) {
            throw new Error('JourneyWhatsApp: failed to resolve Chatwoot contact id.');
        }

        $result = $this->chatwootApiClient->sendOutgoingMessage(
            $conn['platformUrl'],
            $conn['accountApiKey'],
            $conn['externalAccountId'],
            $contactId,
            $conn['externalInboxId'],
            $body,
        );

        $this->stampJourneyOutbound($conn, $result, $journeyContext);

        return $result;
    }

    /**
     * @param array{
     *   journeyId?: string,
     *   journeyRecordId?: string
     * }|null $journeyContext
     * @param array<string, mixed> $conn
     * @param array<string, string> $params number => value
     * @return array{message_id: mixed, conversation_id: mixed}
     */
    public function sendTemplate(
        array $conn,
        string $phone,
        string $name,
        string $templateName,
        string $language,
        array $params = [],
        string $category = 'UTILITY',
        string $content = '',
        ?string $headerMediaUrl = null,
        ?string $headerMediaType = null,
        ?array $journeyContext = null,
    ): array {
        $contact = $this->chatwootApiClient->findOrCreateContact(
            $conn['platformUrl'],
            $conn['accountApiKey'],
            $conn['externalAccountId'],
            $conn['externalInboxId'],
            $phone,
            $name !== '' ? $name : null,
        );

        $contactId = (int) ($contact['id'] ?? 0);
        if ($contactId <= 0) {
            throw new Error('JourneyWhatsApp: failed to resolve Chatwoot contact id.');
        }

        $result = $this->chatwootApiClient->sendTemplateMessage(
            $conn['platformUrl'],
            $conn['accountApiKey'],
            $conn['externalAccountId'],
            $contactId,
            $conn['externalInboxId'],
            $templateName,
            $language,
            $params,
            $category !== '' ? $category : 'UTILITY',
            $content,
            $headerMediaUrl,
            $headerMediaType,
        );

        $this->stampJourneyOutbound($conn, $result, $journeyContext);

        return $result;
    }

    /**
     * Persist conversation trail so inbound WhatsApp can emit `whatsapp_replied`.
     *
     * @param array<string, mixed> $conn
     * @param array{message_id?: mixed, conversation_id?: mixed} $sendResult
     * @param array{journeyId?: string, journeyRecordId?: string}|null $journeyContext
     */
    public function stampJourneyOutbound(
        array $conn,
        array $sendResult,
        ?array $journeyContext,
    ): void {
        if ($journeyContext === null) {
            return;
        }

        $journeyId = isset($journeyContext['journeyId']) ? trim((string) $journeyContext['journeyId']) : '';
        $journeyRecordId = isset($journeyContext['journeyRecordId'])
            ? trim((string) $journeyContext['journeyRecordId'])
            : '';

        if ($journeyId === '' || $journeyRecordId === '') {
            return;
        }

        $conversationExternalId = isset($sendResult['conversation_id'])
            ? trim((string) $sendResult['conversation_id'])
            : '';

        if ($conversationExternalId === '') {
            return;
        }

        $espoAccountId = '';
        $inbox = $conn['inbox'] ?? null;
        if ($inbox instanceof Entity) {
            $espoAccountId = (string) ($inbox->get('chatwootAccountId') ?? '');
        }

        if ($espoAccountId === '') {
            return;
        }

        try {
            $record = $this->entityManager->getEntityById('JourneyRecord', $journeyRecordId);
            if ($record) {
                $record->set('whatsAppChatwootConversationId', $conversationExternalId);
                $record->set('whatsAppChatwootAccountId', $espoAccountId);
                $this->entityManager->saveEntity($record, [SaveOption::SILENT => true]);
            }
        } catch (Throwable $e) {
            $this->log->warning(
                'JourneyWhatsApp: failed to stamp JourneyRecord for reply tracking: ' . $e->getMessage()
            );
        }

        try {
            $conversation = $this->entityManager
                ->getRDBRepository('ChatwootConversation')
                ->where([
                    'chatwootConversationId' => (int) $conversationExternalId,
                    'chatwootAccountId' => $espoAccountId,
                ])
                ->findOne();

            if ($conversation) {
                $conversation->set('journeyId', $journeyId);
                $conversation->set('journeyRecordId', $journeyRecordId);
                $this->entityManager->saveEntity($conversation, [SaveOption::SILENT => true]);
            }
        } catch (Throwable $e) {
            $this->log->warning(
                'JourneyWhatsApp: failed to stamp ChatwootConversation for reply tracking: ' .
                $e->getMessage()
            );
        }
    }

    /**
     * Resolve WhatsAppCampaign-style handlebars mapping against the journey target.
     *
     * @param array<string, mixed>|object $parameterMapping
     * @return array<string, string>
     */
    public function resolveParameterMapping($parameterMapping, Entity $target): array
    {
        if ($parameterMapping instanceof \stdClass) {
            $parameterMapping = (array) $parameterMapping;
        }
        if (is_string($parameterMapping)) {
            $decoded = json_decode($parameterMapping, true);
            $parameterMapping = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($parameterMapping) || $parameterMapping === []) {
            return [];
        }

        $renderer = $this->templateRendererFactory->create();
        $renderer->setEntity($target);

        $joinedExpressions = implode(' ', array_map(
            static fn($v): string => is_string($v) ? $v : '',
            array_values($parameterMapping)
        ));

        try {
            $this->templateBridge->expandInPlace($target);

            $extraData = [];
            $attr = $this->templateBridge->getAttributeName();
            $hostBag = $target->get($attr);

            if (is_array($hostBag) && $hostBag !== []) {
                $extraData[$attr] = $hostBag;
            }

            $needsSkipRelations = false;

            if ($target->hasId() && $joinedExpressions !== '') {
                foreach ($target->getRelationList() as $relation) {
                    $type = $target->getRelationType($relation);

                    if (
                        $type !== Entity::BELONGS_TO &&
                        $type !== Entity::BELONGS_TO_PARENT &&
                        $type !== Entity::HAS_ONE
                    ) {
                        continue;
                    }

                    $needle = '{{' . $relation . '.';
                    if (!str_contains($joinedExpressions, $needle)) {
                        continue;
                    }

                    $needsSkipRelations = true;

                    try {
                        $related = $this->entityManager
                            ->getRelation($target, $relation)
                            ->findOne();
                    } catch (Throwable $e) {
                        $this->log->debug(
                            'JourneyWhatsApp: skip related ' . $relation . ': ' . $e->getMessage()
                        );
                        continue;
                    }

                    if (!$related) {
                        continue;
                    }

                    $this->templateBridge->expandInPlace($related);
                    $extraData[$relation] = $this->entityToTemplateArray($related);
                }
            }

            if ($needsSkipRelations) {
                $renderer->setSkipRelations(true);
            }

            if ($extraData !== []) {
                $renderer->setData($extraData);
            }

            $resolved = [];
            foreach ($parameterMapping as $paramNum => $expression) {
                try {
                    $value = $renderer->renderTemplate(is_string($expression) ? $expression : '');
                    $resolved[(string) $paramNum] = trim($value);
                } catch (Throwable $e) {
                    $this->log->warning(
                        "JourneyWhatsApp: param {$paramNum} failed: " . $e->getMessage()
                    );
                    $resolved[(string) $paramNum] = '';
                }
            }

            return $resolved;
        } finally {
            $this->templateBridge->restoreExpanded();
        }
    }

    /**
     * Free-text failed because Meta 24h session window is closed (or similar).
     */
    public function isOutsideSessionWindow(string $errorMessage): bool
    {
        $m = strtolower($errorMessage);

        foreach ([
            '131047',
            're-engagement',
            'reengagement',
            'outside the allowed window',
            '24 hour',
            '24-hour',
            '24h',
            'customer service window',
            'session window',
            'message failed to send because more than 24 hours',
        ] as $needle) {
            if (str_contains($m, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function entityToTemplateArray(Entity $entity): array
    {
        $map = $entity->getValueMap();

        if (is_object($map)) {
            $map = get_object_vars($map);
        }

        if (!is_array($map)) {
            return [];
        }

        $encoded = json_encode($map);
        if (!is_string($encoded)) {
            return $map;
        }

        $decoded = json_decode($encoded, true);

        return is_array($decoded) ? $decoded : $map;
    }
}

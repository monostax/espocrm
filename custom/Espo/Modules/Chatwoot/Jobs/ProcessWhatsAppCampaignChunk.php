<?php
/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

namespace Espo\Modules\Chatwoot\Jobs;

use Espo\Core\Exceptions\Error;
use Espo\Core\FileStorage\Manager as FileStorageManager;
use Espo\Core\Htmlizer\TemplateRendererFactory;
use Espo\Core\Job\Job;
use Espo\Core\Job\Job\Data;
use Espo\Core\Utils\Log;
use Espo\Entities\Attachment;
use Espo\Modules\Chatwoot\Services\ChatwootApiClient;
use Espo\Modules\Chatwoot\Services\WhatsAppCampaignOpportunityService;
use Espo\Modules\Chatwoot\Services\WhatsAppOptOutService;
use Espo\Modules\Chatwoot\Tools\WhatsAppChannel;
use Espo\Modules\Chatwoot\Tools\WhatsAppMedia;
use Espo\Modules\Global\Tools\CustomField\TemplateBridge;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Async job to process a chunk of WhatsApp campaign contacts.
 *
 * Sends template messages via Chatwoot API with rate limiting.
 * Each chunk processes CHUNK_SIZE contacts, with a configurable delay
 * between sends to respect API rate limits.
 *
 * When the campaign has a parameterMapping, each contact's parameters
 * are resolved using EspoCRM's TemplateRenderer (Handlebars) against
 * the Contact entity, enabling per-contact personalization.
 */
class ProcessWhatsAppCampaignChunk implements Job
{
    /**
     * Delay between message sends in milliseconds.
     * 1500ms provides breathing room when multiple campaigns overlap.
     */
    private const RATE_LIMIT_DELAY_MS = 1500;

    /** Maximum number of retry attempts for transient failures per contact. */
    private const MAX_RETRIES = 3;

    /** Delay in seconds between retry passes within the same job run. */
    private const RETRY_BACKOFF_SECONDS = 3;

    public function __construct(
        private EntityManager $entityManager,
        private ChatwootApiClient $chatwootApiClient,
        private TemplateRendererFactory $templateRendererFactory,
        private WhatsAppOptOutService $optOutService,
        private WhatsAppCampaignOpportunityService $opportunityService,
        private TemplateBridge $templateBridge,
        private FileStorageManager $fileStorageManager,
        private Log $log,
    ) {}

    /**
     * Process a chunk of campaign contacts.
     *
     * @param Data $data Job data containing campaignId, chunkOffset, chunkSize
     * @throws Error
     */
    public function run(Data $data): void
    {
        $campaignId = $data->get('campaignId');
        $chunkOffset = $data->get('chunkOffset');
        $chunkSize = $data->get('chunkSize');

        // Preferred data shape: an explicit list of WhatsAppCampaignContact IDs.
        // Legacy jobs (queued before deploy) may still carry chunkOffset/chunkSize.
        $campaignContactIds = $data->get('campaignContactIds');

        if (!is_array($campaignContactIds)) {
            $campaignContactIds = [];
        }

        $chunkLabel = !empty($campaignContactIds)
            ? count($campaignContactIds) . ' explicit contacts'
            : "offset {$chunkOffset}";

        $this->log->info("ProcessWhatsAppCampaignChunk: Starting chunk ({$chunkLabel}) for campaign {$campaignId}");

        // Check if campaign was cancelled
        $campaign = $this->entityManager->getEntityById('WhatsAppCampaign', $campaignId);

        if (!$campaign) {
            $this->log->warning("ProcessWhatsAppCampaignChunk: Campaign {$campaignId} not found, skipping.");
            return;
        }

        if ($campaign->get('status') === 'Cancelled') {
            $this->log->info("ProcessWhatsAppCampaignChunk: Campaign {$campaignId} was cancelled, skipping chunk.");
            return;
        }

        $chatwootAccountId = $campaign->get('chatwootAccountId');
        $chatwootAccount = $this->entityManager->getEntityById('ChatwootAccount', $chatwootAccountId);

        if (!$chatwootAccount) {
            $this->failCampaign($campaignId, "Chatwoot account {$chatwootAccountId} not found.");
            return;
        }

        $platform = $this->entityManager->getEntityById('ChatwootPlatform', $chatwootAccount->get('platformId'));

        if (!$platform) {
            $this->failCampaign($campaignId, "Chatwoot platform not found for account {$chatwootAccountId}.");
            return;
        }

        $platformUrl = $platform->get('backendUrl');
        $accountApiKey = $chatwootAccount->get('apiKey');
        $chatwootAccountIdExternal = $chatwootAccount->get('chatwootAccountId');

        if (!$platformUrl || !$accountApiKey || !$chatwootAccountIdExternal) {
            $this->failCampaign($campaignId, "Missing Chatwoot connection details (URL, API key, or account ID).");
            return;
        }

        $whatsappInbox = null;
        $selectedInboxId = $campaign->get('chatwootInboxId');

        if ($selectedInboxId) {
            $whatsappInbox = $this->entityManager->getEntityById('ChatwootInbox', $selectedInboxId);

            if (
                $whatsappInbox &&
                $whatsappInbox->get('chatwootAccountId') !== $chatwootAccountId
            ) {
                $whatsappInbox = null;
            }
        }

        if (!$whatsappInbox) {
            // Legacy campaigns without an explicit inbox are always Meta
            // template campaigns; never silently fall back to a QR inbox.
            $whatsappInbox = $this->entityManager
                ->getRDBRepository('ChatwootInbox')
                ->where([
                    'chatwootAccountId' => $chatwootAccountId,
                    'channelType' => WhatsAppChannel::TEMPLATE_CAPABLE,
                ])
                ->findOne();
        }

        $inboxId = $whatsappInbox ? $whatsappInbox->get('chatwootInboxId') : null;

        if (!$inboxId) {
            $this->failCampaign(
                $campaignId,
                'No sendable WhatsApp inbox found for campaign (select an active WhatsApp inbox).'
            );
            return;
        }

        $channelType = $whatsappInbox->get('channelType') ?: $campaign->get('channelType');
        $messageMode = WhatsAppChannel::normalizeMode($campaign->get('messageMode'));

        if (!WhatsAppChannel::supportsMode($channelType, $messageMode)) {
            $this->failCampaign($campaignId, sprintf(
                'Campaign is in %s mode, which a %s inbox cannot send.',
                $messageMode,
                WhatsAppChannel::label($channelType),
            ));
            return;
        }

        $templateName = $campaign->get('templateName');
        $templateLanguage = $campaign->get('templateLanguage');
        $templateCategory = $campaign->get('templateCategory') ?: 'UTILITY';
        $templateBody = $campaign->get('templateBody') ?: '';
        $headerMediaUrl = $campaign->get('headerMediaUrl') ?: null;
        $headerMediaType = $campaign->get('headerMediaType') ?: null;
        $messageBody = (string) ($campaign->get('messageBody') ?: '');

        if ($messageMode === WhatsAppChannel::MODE_TEMPLATE && !$templateName) {
            $this->failCampaign($campaignId, 'Campaign has no WhatsApp template selected.');
            return;
        }

        if ($messageMode === WhatsAppChannel::MODE_FREE_TEXT && trim($messageBody) === '' && !$campaign->get('attachmentId')) {
            $this->failCampaign($campaignId, 'Campaign has neither a message body nor an attachment.');
            return;
        }

        $parameterMapping = $campaign->get('parameterMapping');
        if ($parameterMapping instanceof \stdClass) {
            $parameterMapping = (array) $parameterMapping;
        } elseif (is_string($parameterMapping)) {
            $parameterMapping = json_decode($parameterMapping, true);
        }
        if (!is_array($parameterMapping)) {
            $parameterMapping = [];
        }

        [$delayMinMs, $delayMaxMs] = WhatsAppChannel::sendDelayWindowMs($channelType);

        // Materialize campaign media once per chunk, not once per recipient:
        // every recipient gets the same bytes, and re-reading them from object
        // storage for each send would dominate the job's runtime.
        $media = null;

        if ($messageMode === WhatsAppChannel::MODE_FREE_TEXT) {
            try {
                $media = $this->materializeAttachment($campaign);
            } catch (\Throwable $e) {
                $this->failCampaign($campaignId, 'Could not read campaign attachment: ' . $e->getMessage());
                return;
            }
        }

        $sendContext = [
            'campaignId' => $campaignId,
            'platformUrl' => $platformUrl,
            'accountApiKey' => $accountApiKey,
            'chatwootAccountIdExternal' => $chatwootAccountIdExternal,
            'inboxId' => $inboxId,
            'channelType' => $channelType,
            'messageMode' => $messageMode,
            'messageBody' => $messageBody,
            'media' => $media,
            'templateName' => $templateName,
            'templateLanguage' => $templateLanguage,
            'templateCategory' => $templateCategory,
            'templateBody' => $templateBody,
            'headerMediaUrl' => $headerMediaUrl,
            'headerMediaType' => $headerMediaType,
            'parameterMapping' => $parameterMapping,
            'createOpportunity' => (bool) $campaign->get('createOpportunity'),
            'delayMinMs' => $delayMinMs,
            'delayMaxMs' => $delayMaxMs,
        ];

        try {
            // --- First pass: process all Pending contacts in this chunk ---
            $contacts = $this->findChunkContacts($campaignId, 'Pending', $campaignContactIds, $chunkOffset, $chunkSize);

            $processedCount = $this->processContacts($contacts, $sendContext);

            $this->log->info("ProcessWhatsAppCampaignChunk: First pass ({$chunkLabel}) for campaign {$campaignId} ({$processedCount} contacts).");

            // --- Retry passes: re-process contacts marked as Retry ---
            for ($retryPass = 1; $retryPass <= self::MAX_RETRIES; $retryPass++) {
                $retryContacts = $this->findChunkContacts($campaignId, 'Retry', $campaignContactIds, $chunkOffset, $chunkSize);

                $retryCount = count($retryContacts);

                if ($retryCount === 0) {
                    break;
                }

                $this->log->info("ProcessWhatsAppCampaignChunk: Retry pass {$retryPass} for campaign {$campaignId} ({$retryCount} contacts).");

                sleep(self::RETRY_BACKOFF_SECONDS);

                $this->processContacts($retryContacts, $sendContext);
            }

            // --- Finalize any contacts still in Retry after all passes ---
            $this->finalizeRemainingRetries($campaignId, $campaignContactIds, $chunkOffset, $chunkSize);

            $this->log->info("ProcessWhatsAppCampaignChunk: Completed chunk ({$chunkLabel}) for campaign {$campaignId}.");

            $this->verifyMessageStatuses(
                $campaignId,
                $platformUrl,
                $accountApiKey,
                $chatwootAccountIdExternal,
                $campaignContactIds
            );

            $this->checkCampaignCompletion($campaignId);
        } finally {
            if ($media !== null) {
                $this->discardMedia($media);
            }
        }
    }

    /**
     * Copy the campaign's attachment to a local temp file for upload.
     *
     * Attachment bytes may live in object storage, so a local path is not
     * guaranteed; contents are streamed to a temp file with the original
     * extension preserved so Chatwoot's MIME sniffing agrees with the type we
     * advertise.
     *
     * @return array{path: string, mimeType: string, fileName: string}|null
     * @throws Error
     */
    private function materializeAttachment(Entity $campaign): ?array
    {
        $attachmentId = $campaign->get('attachmentId');

        if (!$attachmentId) {
            return null;
        }

        /** @var ?Attachment $attachment */
        $attachment = $this->entityManager->getEntityById(Attachment::ENTITY_TYPE, $attachmentId);

        if (!$attachment) {
            throw new Error("Attachment {$attachmentId} not found.");
        }

        $size = (int) $this->fileStorageManager->getSize($attachment);

        if ($size > WhatsAppMedia::MAX_BYTES) {
            throw new Error(sprintf(
                'Attachment is %.1f MB; WhatsApp media is limited to %d MB.',
                $size / 1048576,
                (int) (WhatsAppMedia::MAX_BYTES / 1048576)
            ));
        }

        $fileName = (string) ($attachment->get('name') ?: 'attachment');

        $mimeType = WhatsAppMedia::effectiveMimeType(
            $attachment->get('type'),
            (bool) $campaign->get('sendAudioAsVoice')
        );

        $tempPath = tempnam(sys_get_temp_dir(), 'wa-campaign-');

        if ($tempPath === false) {
            throw new Error('Could not create temp file for campaign attachment.');
        }

        $extension = pathinfo($fileName, PATHINFO_EXTENSION);

        if ($extension !== '') {
            $withExtension = $tempPath . '.' . $extension;

            if (@rename($tempPath, $withExtension)) {
                $tempPath = $withExtension;
            }
        }

        try {
            $contents = $this->fileStorageManager->getContents($attachment);

            if (file_put_contents($tempPath, $contents) === false) {
                throw new Error('Could not write campaign attachment to temp file.');
            }
        } catch (\Throwable $e) {
            @unlink($tempPath);

            throw $e instanceof Error ? $e : new Error($e->getMessage());
        }

        $this->log->info(sprintf(
            'ProcessWhatsAppCampaignChunk: Campaign %s media ready (%s, %s, %d bytes) — will send as %s.',
            $campaign->getId(),
            $fileName,
            $mimeType,
            $size,
            WhatsAppMedia::describe(
                $attachment->get('type'),
                (bool) $campaign->get('sendAudioAsVoice')
            )
        ));

        return [
            'path' => $tempPath,
            'mimeType' => $mimeType,
            'fileName' => $fileName,
        ];
    }

    /**
     * @param array{path: string, mimeType: string, fileName: string} $media
     */
    private function discardMedia(array $media): void
    {
        if (is_file($media['path'])) {
            @unlink($media['path']);
        }
    }

    /**
     * Fetch campaign contacts for this chunk in a given status.
     *
     * Uses the explicit ID list when provided (enrollment-safe); falls back
     * to offset paging for legacy jobs queued before the ID-based scheme.
     *
     * @param string[] $campaignContactIds
     * @return \Espo\ORM\EntityCollection<\Espo\ORM\Entity>
     */
    private function findChunkContacts(
        string $campaignId,
        string $status,
        array $campaignContactIds,
        ?int $chunkOffset,
        ?int $chunkSize
    ): iterable {
        $where = [
            'whatsAppCampaignId' => $campaignId,
            'status' => $status,
        ];

        if (!empty($campaignContactIds)) {
            $where['id'] = $campaignContactIds;
        }

        $builder = $this->entityManager
            ->getRDBRepository('WhatsAppCampaignContact')
            ->where($where)
            ->order('createdAt');

        if (empty($campaignContactIds)) {
            $builder = $builder->limit((int) $chunkOffset, (int) $chunkSize);
        }

        return $builder->find();
    }

    /**
     * Process a collection of campaign contacts (either Pending or Retry).
     *
     * @return int Number of contacts processed
     */
    private function processContacts(iterable $contacts, array $ctx): int
    {
        $processedCount = 0;
        $campaignId = $ctx['campaignId'];

        foreach ($contacts as $campaignContact) {
            if ($processedCount > 0 && $processedCount % 10 === 0) {
                $campaign = $this->entityManager->getEntityById('WhatsAppCampaign', $campaignId);
                if ($campaign && $campaign->get('status') === 'Cancelled') {
                    $this->log->info("ProcessWhatsAppCampaignChunk: Campaign {$campaignId} cancelled mid-chunk.");
                    return $processedCount;
                }
            }

            // Atomically claim the row (Pending/Retry -> Processing) before
            // any network I/O. A concurrent or duplicate job cannot claim the
            // same row, so a recipient can never be sent twice.
            if (!$this->claimContact($campaignContact)) {
                $this->log->info(
                    "ProcessWhatsAppCampaignChunk: Contact {$campaignContact->getId()} " .
                    "already claimed by another worker; skipping."
                );

                continue;
            }

            try {
                if ($processedCount > 0) {
                    $this->pauseBetweenSends($ctx);
                }

                $phoneNumber = $campaignContact->get('phoneNumber');
                $contactName = $campaignContact->get('contactName');

                $chatwootContact = $this->chatwootApiClient->findOrCreateContact(
                    $ctx['platformUrl'],
                    $ctx['accountApiKey'],
                    $ctx['chatwootAccountIdExternal'],
                    $ctx['inboxId'],
                    $phoneNumber,
                    $contactName
                );

                $chatwootContactId = $chatwootContact['id'] ?? null;

                if (!$chatwootContactId) {
                    throw new Error("Failed to get Chatwoot contact ID for phone {$phoneNumber}.");
                }

                if ($ctx['messageMode'] === WhatsAppChannel::MODE_FREE_TEXT) {
                    [$result, $params] = $this->sendFreeText($ctx, $chatwootContactId, $campaignContact);
                } else {
                    [$result, $params] = $this->sendTemplate($ctx, $chatwootContactId, $campaignContact);
                }

                $messageId = trim((string) ($result['message_id'] ?? ''));
                $conversationId = trim((string) ($result['conversation_id'] ?? ''));

                if ($messageId === '' || $conversationId === '') {
                    throw new Error(
                        'Chatwoot accepted the send but did not return message and conversation IDs; refusing Opportunity attribution.'
                    );
                }

                $campaignContact->set([
                    'status' => 'Sent',
                    'chatwootMessageId' => $messageId,
                    'chatwootConversationId' => $conversationId,
                    'sentAt' => date('Y-m-d H:i:s'),
                    'processedParams' => $params,
                ]);
                $this->entityManager->saveEntity($campaignContact);

            } catch (\Exception $e) {
                $errorMessage = $e->getMessage();
                $this->log->error("ProcessWhatsAppCampaignChunk: Failed to process contact {$campaignContact->getId()}: {$errorMessage}");

                $currentRetries = (int) $campaignContact->get('retryCount');

                if ($this->isTransientFailure($errorMessage) && $currentRetries < self::MAX_RETRIES) {
                    $campaignContact->set([
                        'status' => 'Retry',
                        'retryCount' => $currentRetries + 1,
                        'failedReason' => substr($errorMessage, 0, 5000),
                    ]);
                    $this->entityManager->saveEntity($campaignContact);

                    $this->log->info(
                        "ProcessWhatsAppCampaignChunk: Contact {$campaignContact->getId()} " .
                        "marked for retry ({$currentRetries} -> " . ($currentRetries + 1) . ")"
                    );
                } else {
                    $campaignContact->set([
                        'status' => 'Failed',
                        'failedAt' => date('Y-m-d H:i:s'),
                        'failedReason' => substr($errorMessage, 0, 5000),
                        'opportunityAttributionStatus' => 'NotRequested',
                    ]);
                    $this->entityManager->saveEntity($campaignContact);

                    $this->incrementCampaignCounter($campaignId, 'failedCount');

                    if ($this->optOutService->isPermanentFailure($errorMessage)) {
                        $contactId = $campaignContact->get('contactId');
                        if ($contactId) {
                            $this->optOutService->autoOptOutContact($contactId, $campaignId, $errorMessage);
                        }
                    }
                }
            }

            if ($campaignContact->get('status') === 'Sent') {
                try {
                    $this->incrementCampaignCounter($campaignId, 'sentCount');
                } catch (\Throwable $e) {
                    $this->log->error(sprintf(
                        'ProcessWhatsAppCampaignChunk: Could not increment sent counter for recipient=%s: %s',
                        $campaignContact->getId(),
                        $e->getMessage(),
                    ));
                }

                if ($ctx['createOpportunity']) {
                    $this->attributeOpportunity($campaignContact);
                }
            }

            $processedCount++;
        }

        return $processedCount;
    }

    /**
     * Sleep between two consecutive sends on the same inbox.
     *
     * Cloud API uses a flat delay; QR (WAHA) uses a randomized window so the
     * traffic pattern on a real handset number does not look machine-generated.
     *
     * @param array<string, mixed> $ctx
     */
    private function pauseBetweenSends(array $ctx): void
    {
        $minMs = (int) ($ctx['delayMinMs'] ?? self::RATE_LIMIT_DELAY_MS);
        $maxMs = (int) ($ctx['delayMaxMs'] ?? $minMs);

        if ($maxMs < $minMs) {
            $maxMs = $minMs;
        }

        $delayMs = $minMs === $maxMs ? $minMs : random_int($minMs, $maxMs);

        usleep($delayMs * 1000);
    }

    /**
     * Send a Meta message template (Cloud API / Coexistence).
     *
     * @param array<string, mixed> $ctx
     * @return array{0: array<string, mixed>, 1: array<string, string>} [sendResult, resolvedParams]
     * @throws Error
     */
    private function sendTemplate(array $ctx, int|string $chatwootContactId, Entity $campaignContact): array
    {
        $params = $this->resolveParameterMapping(
            $ctx['parameterMapping'],
            $campaignContact->get('contactId')
        );

        $content = $this->renderTemplateContent($ctx['templateBody'], $params);

        $result = $this->chatwootApiClient->sendTemplateMessage(
            $ctx['platformUrl'],
            $ctx['accountApiKey'],
            $ctx['chatwootAccountIdExternal'],
            $chatwootContactId,
            $ctx['inboxId'],
            $ctx['templateName'],
            $ctx['templateLanguage'],
            $params,
            $ctx['templateCategory'],
            $content,
            $ctx['headerMediaUrl'],
            $ctx['headerMediaType']
        );

        return [$result, $params];
    }

    /**
     * Send a free-text message (QR / WAHA, or Cloud inside the 24h window),
     * optionally carrying the campaign's media attachment.
     *
     * The whole body is a Handlebars template rendered against the recipient's
     * Contact, so `{{firstName}}` style personalization works without the
     * numbered-parameter indirection Meta templates require. When media is
     * attached the body becomes its caption, and may be empty (a bare voice
     * note is a legitimate send).
     *
     * @param array<string, mixed> $ctx
     * @return array{0: array<string, mixed>, 1: array<string, string>} [sendResult, resolvedParams]
     * @throws Error
     */
    private function sendFreeText(array $ctx, int|string $chatwootContactId, Entity $campaignContact): array
    {
        $body = $this->renderBodyForContact(
            (string) $ctx['messageBody'],
            $campaignContact->get('contactId')
        );

        $media = $ctx['media'] ?? null;

        if ($media === null) {
            if (trim($body) === '') {
                throw new Error('Rendered message body is empty; refusing to send a blank WhatsApp message.');
            }

            $result = $this->chatwootApiClient->sendOutgoingMessage(
                $ctx['platformUrl'],
                $ctx['accountApiKey'],
                $ctx['chatwootAccountIdExternal'],
                $chatwootContactId,
                $ctx['inboxId'],
                $body
            );

            return [$result, ['body' => $body]];
        }

        $result = $this->chatwootApiClient->sendOutgoingMessageWithAttachment(
            $ctx['platformUrl'],
            $ctx['accountApiKey'],
            $ctx['chatwootAccountIdExternal'],
            $chatwootContactId,
            $ctx['inboxId'],
            $body,
            $media['path'],
            $media['mimeType'],
            $media['fileName']
        );

        // Persisted on the recipient row for auditability: free-text campaigns
        // have no numbered params, so the rendered caption plus the media
        // identity is the record of what was actually sent.
        return [$result, [
            'body' => $body,
            'attachment' => $media['fileName'],
            'attachmentMimeType' => $media['mimeType'],
        ]];
    }

    /**
     * Atomically claim a recipient row before sending.
     *
     * Performs a conditional UPDATE (id + expected status) so that exactly
     * one worker can transition the row from Pending/Retry to Processing.
     * Rows left in Processing by a crashed worker are recovered by the
     * RepairWhatsAppCampaignRecipients job — they are never resent, because
     * the message may already have been accepted remotely.
     */
    private function claimContact(Entity $campaignContact): bool
    {
        $expectedStatus = $campaignContact->get('status');

        if (!in_array($expectedStatus, ['Pending', 'Retry'], true)) {
            return false;
        }

        $now = date('Y-m-d H:i:s');

        $updateQuery = $this->entityManager
            ->getQueryBuilder()
            ->update()
            ->in('WhatsAppCampaignContact')
            ->set([
                'status' => 'Processing',
                'claimedAt' => $now,
            ])
            ->where([
                'id' => $campaignContact->getId(),
                'status' => $expectedStatus,
            ])
            ->build();

        $sth = $this->entityManager->getQueryExecutor()->execute($updateQuery);

        if ($sth->rowCount() === 0) {
            return false;
        }

        $campaignContact->set([
            'status' => 'Processing',
            'claimedAt' => $now,
        ]);

        return true;
    }

    /**
     * Opportunity attribution has its own failure state. It must never throw
     * into the message retry path after the template send was persisted.
     */
    private function attributeOpportunity(Entity $campaignContact): void
    {
        try {
            $this->opportunityService->attributeSuccessfulSend($campaignContact);
        } catch (\Throwable $e) {
            $this->log->error(sprintf(
                'ProcessWhatsAppCampaignChunk: Opportunity attribution failed for recipient=%s: %s',
                $campaignContact->getId(),
                $e->getMessage(),
            ));

            try {
                $fresh = $this->entityManager->getEntityById(
                    'WhatsAppCampaignContact',
                    $campaignContact->getId()
                );

                if ($fresh) {
                    $fresh->set([
                        'opportunityAttributionStatus' => 'Failed',
                        'opportunityAttributionError' => substr($e->getMessage(), 0, 5000),
                    ]);
                    $this->entityManager->saveEntity($fresh);
                }
            } catch (\Throwable $persistenceError) {
                $this->log->error(sprintf(
                    'ProcessWhatsAppCampaignChunk: Could not persist Opportunity attribution failure for recipient=%s: %s',
                    $campaignContact->getId(),
                    $persistenceError->getMessage(),
                ));
            }
        }
    }

    /**
     * Mark any contacts still in Retry status as permanently Failed
     * after all retry passes have been exhausted.
     *
     * @param string[] $campaignContactIds
     */
    private function finalizeRemainingRetries(
        string $campaignId,
        array $campaignContactIds,
        ?int $chunkOffset,
        ?int $chunkSize
    ): void {
        $remaining = $this->findChunkContacts($campaignId, 'Retry', $campaignContactIds, $chunkOffset, $chunkSize);

        foreach ($remaining as $campaignContact) {
            $lastReason = $campaignContact->get('failedReason') ?: 'Max retries exhausted';

            $campaignContact->set([
                'status' => 'Failed',
                'failedAt' => date('Y-m-d H:i:s'),
                'failedReason' => $lastReason,
                'opportunityAttributionStatus' => 'NotRequested',
            ]);
            $this->entityManager->saveEntity($campaignContact);

            $this->incrementCampaignCounter($campaignId, 'failedCount');

            $this->log->warning(
                "ProcessWhatsAppCampaignChunk: Contact {$campaignContact->getId()} " .
                "failed after {$campaignContact->get('retryCount')} retries: {$lastReason}"
            );
        }
    }

    /**
     * Increment a campaign counter atomically.
     *
     * @param string $campaignId Campaign entity ID
     * @param string $field Counter field name
     */
    private function incrementCampaignCounter(string $campaignId, string $field): void
    {
        $campaign = $this->entityManager->getEntityById('WhatsAppCampaign', $campaignId);

        if ($campaign) {
            $currentValue = (int) $campaign->get($field);
            $campaign->set($field, $currentValue + 1);
            $this->entityManager->saveEntity($campaign);
        }
    }

    /**
     * Check if all campaign contacts have been processed and mark completion.
     *
     * Campaigns with continuous enrollment enabled, or managed by an Active
     * continuous campaign distribution, are never auto-completed; they stay
     * in Sending until enrollment / the distribution is stopped or the
     * campaign is aborted.
     *
     * @param string $campaignId Campaign entity ID
     */
    private function checkCampaignCompletion(string $campaignId): void
    {
        $pendingOrRetryCount = $this->entityManager
            ->getRDBRepository('WhatsAppCampaignContact')
            ->where([
                'whatsAppCampaignId' => $campaignId,
                'status' => ['Pending', 'Retry', 'Processing'],
            ])
            ->count();

        if ($pendingOrRetryCount === 0) {
            $campaign = $this->entityManager->getEntityById('WhatsAppCampaign', $campaignId);

            if (
                $campaign &&
                $campaign->get('status') === 'Sending' &&
                !$campaign->get('continuousEnrollment') &&
                !$this->isInActiveDistribution($campaignId)
            ) {
                $campaign->set([
                    'status' => 'Completed',
                    'completedAt' => date('Y-m-d H:i:s'),
                ]);
                $this->entityManager->saveEntity($campaign);

                $this->log->info("ProcessWhatsAppCampaignChunk: Campaign {$campaignId} completed.");
            }
        }
    }

    /**
     * Whether the campaign is an executor of an Active continuous
     * campaign distribution (allocation layer keeps enrolling recipients).
     */
    private function isInActiveDistribution(string $campaignId): bool
    {
        $entries = $this->entityManager
            ->getRDBRepository('WhatsAppCampaignDistributionEntry')
            ->where(['campaignId' => $campaignId])
            ->find();

        foreach ($entries as $entry) {
            $distributionId = $entry->get('distributionId');

            if (!$distributionId) {
                continue;
            }

            $distribution = $this->entityManager
                ->getEntityById('WhatsAppCampaignDistribution', $distributionId);

            if (
                $distribution &&
                $distribution->get('status') === 'Active' &&
                $distribution->get('continuous')
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * After sending, wait briefly and then verify each message's delivery status
     * via the Chatwoot API. This catches async failures (e.g. Meta rejecting the
     * template) that happen after Chatwoot's initial 200 response.
     *
     * When an explicit contact ID list is provided (ID-based chunks), verification
     * is restricted to those rows so long-running enrollment campaigns don't
     * re-verify every previously sent contact on each chunk.
     *
     * @param string[] $campaignContactIds
     */
    private function verifyMessageStatuses(
        string $campaignId,
        string $platformUrl,
        string $accountApiKey,
        int $chatwootAccountId,
        array $campaignContactIds = []
    ): void {
        $where = [
            'whatsAppCampaignId' => $campaignId,
            'status' => 'Sent',
        ];

        if (!empty($campaignContactIds)) {
            $where['id'] = $campaignContactIds;
        }

        $sentContacts = $this->entityManager
            ->getRDBRepository('WhatsAppCampaignContact')
            ->where($where)
            ->where(['chatwootMessageId!=' => ''])
            ->where(['chatwootConversationId!=' => ''])
            ->find();

        $contactsByConversation = [];
        foreach ($sentContacts as $contact) {
            $convId = $contact->get('chatwootConversationId');
            $contactsByConversation[$convId][] = $contact;
        }

        if (empty($contactsByConversation)) {
            return;
        }

        sleep(5);

        foreach ($contactsByConversation as $conversationId => $contacts) {
            try {
                $messages = $this->chatwootApiClient->getConversationMessages(
                    $platformUrl,
                    $accountApiKey,
                    $chatwootAccountId,
                    (int) $conversationId
                );

                $statusByMessageId = [];
                $errorByMessageId = [];
                foreach ($messages as $msg) {
                    if (isset($msg['id'])) {
                        $statusByMessageId[(string) $msg['id']] = $msg['status'] ?? null;
                        $errorByMessageId[(string) $msg['id']] =
                            $msg['content_attributes']['external_error'] ?? null;
                    }
                }

                foreach ($contacts as $contact) {
                    $msgId = $contact->get('chatwootMessageId');
                    $chatwootStatus = $statusByMessageId[$msgId] ?? null;

                    if ($chatwootStatus !== 'failed') {
                        continue;
                    }

                    // Webhooks may have already advanced status during sleep(5);
                    // only attack contacts still in Sent to avoid double-counting.
                    $fresh = $this->entityManager->getEntityById(
                        'WhatsAppCampaignContact',
                        $contact->getId()
                    );

                    if (!$fresh || $fresh->get('status') !== 'Sent') {
                        continue;
                    }

                    $reason = $errorByMessageId[$msgId] ?? 'Delivery failed (detected via post-send verification)';

                    $fresh->set([
                        'status' => 'Failed',
                        'failedAt' => date('Y-m-d H:i:s'),
                        'failedReason' => substr((string) $reason, 0, 5000),
                    ]);
                    $this->entityManager->saveEntity($fresh);

                    $this->recalculateCampaignCounters($campaignId);

                    if ($this->optOutService->isPermanentFailure((string) $reason)) {
                        $contactId = $fresh->get('contactId');
                        if ($contactId) {
                            $this->optOutService->autoOptOutContact($contactId, $campaignId, (string) $reason);
                        }
                    }

                    $this->log->warning(
                        "ProcessWhatsAppCampaignChunk: Post-send verification detected failure " .
                        "for contact {$fresh->getId()} (message {$msgId}): {$reason}"
                    );
                }
            } catch (\Throwable $e) {
                $this->log->warning(
                    "ProcessWhatsAppCampaignChunk: Post-send verification failed for conversation " .
                    "{$conversationId}: {$e->getMessage()}"
                );
            }
        }

        $this->recalculateCampaignCounters($campaignId);
    }

    /**
     * Rebuild aggregate counters from contact rows (absolute values, race-safe with webhooks).
     */
    private function recalculateCampaignCounters(string $campaignId): void
    {
        $campaign = $this->entityManager->getEntityById('WhatsAppCampaign', $campaignId);

        if (!$campaign) {
            return;
        }

        $repo = $this->entityManager->getRDBRepository('WhatsAppCampaignContact');

        $countForStatuses = function (array $statuses) use ($repo, $campaignId): int {
            $total = 0;

            foreach ($statuses as $status) {
                $total += $repo
                    ->where(['whatsAppCampaignId' => $campaignId, 'status' => $status])
                    ->count();
            }

            return $total;
        };

        $campaign->set([
            'sentCount' => $countForStatuses(['Sent', 'Delivered', 'Read', 'Replied']),
            'deliveredCount' => $countForStatuses(['Delivered', 'Read', 'Replied']),
            'readCount' => $countForStatuses(['Read', 'Replied']),
            'repliedCount' => $countForStatuses(['Replied']),
            'failedCount' => $countForStatuses(['Failed']),
        ]);

        $this->entityManager->saveEntity($campaign);
    }

    /**
     * Render a free-text message body against the recipient's Contact.
     *
     * Reuses the Meta-template parameter resolver (custom-field expansion,
     * `{{account.*}}` link handling) by treating the whole body as a single
     * named expression.
     *
     * Handlebars HTML-escapes `{{token}}` output, which is correct for the
     * email/PDF templates the renderer was built for but always wrong on a
     * plain-text channel: a contact named "Tom & Jerry" must not arrive as
     * "Tom &amp; Jerry". Entities are decoded back after rendering.
     */
    private function renderBodyForContact(string $body, ?string $contactId): string
    {
        if (trim($body) === '') {
            return '';
        }

        // Static body: nothing to resolve, and no reason to require a
        // loadable Contact before sending.
        if (!str_contains($body, '{{')) {
            return $body;
        }

        $resolved = $this->resolveParameterMapping(['body' => $body], $contactId);

        $rendered = $resolved['body'] ?? '';

        if ($rendered === '') {
            return '';
        }

        return html_entity_decode($rendered, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * Resolve parameterMapping expressions against a Contact entity using
     * EspoCRM's TemplateRenderer (Handlebars). Falls back to empty string
     * if a contact cannot be loaded or a field expression fails.
     *
     * @param array<string, string> $parameterMapping e.g. ["1" => "{{firstName}}", "2" => "{{account.name}}"]
     * @param string|null $contactId
     * @return array<string, string> Resolved values, e.g. ["1" => "John", "2" => "Acme Corp"]
     */
    private function resolveParameterMapping(array $parameterMapping, ?string $contactId): array
    {
        if (empty($parameterMapping)) {
            return [];
        }

        if (!$contactId) {
            $this->log->warning("ProcessWhatsAppCampaignChunk: No contactId, returning empty params.");
            return array_fill_keys(array_keys($parameterMapping), '');
        }

        $contact = $this->entityManager->getEntityById('Contact', $contactId);

        if (!$contact) {
            $this->log->warning("ProcessWhatsAppCampaignChunk: Contact {$contactId} not found, returning empty params.");
            return array_fill_keys(array_keys($parameterMapping), '');
        }

        $renderer = $this->templateRendererFactory->create();
        $renderer->setEntity($contact);

        // Nest customFields on the Contact (and any referenced belongsTo hosts)
        // before Handlebars runs. Htmlizer applyOneLinks loads FRESH related
        // entities and would wipe setData link overlays — so when a mapping
        // references {{account.*}} we skip relations and inject link maps with
        // nested CF bags ourselves.
        $joinedExpressions = implode(' ', array_map(
            static fn($v): string => is_string($v) ? $v : '',
            array_values($parameterMapping)
        ));

        try {
            $this->templateBridge->expandInPlace($contact);

            $extraData = [];
            $attr = $this->templateBridge->getAttributeName();
            $hostBag = $contact->get($attr);

            if (is_array($hostBag) && $hostBag !== []) {
                $extraData[$attr] = $hostBag;
            }

            $needsSkipRelations = false;

            if ($contact->hasId() && $joinedExpressions !== '') {
                foreach ($contact->getRelationList() as $relation) {
                    $type = $contact->getRelationType($relation);

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
                            ->getRelation($contact, $relation)
                            ->findOne();
                    } catch (\Throwable $e) {
                        $this->log->debug(
                            'ProcessWhatsAppCampaignChunk: skip related ' .
                            $relation . ': ' . $e->getMessage()
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

            $resolvedParams = [];

            foreach ($parameterMapping as $paramNum => $expression) {
                try {
                    $resolved = $renderer->renderTemplate(is_string($expression) ? $expression : '');
                    $resolvedParams[(string) $paramNum] = trim($resolved);
                } catch (\Throwable $e) {
                    $this->log->warning(
                        "ProcessWhatsAppCampaignChunk: Failed to resolve param {$paramNum} " .
                        "('{$expression}') for contact {$contactId}: {$e->getMessage()}"
                    );
                    $resolvedParams[(string) $paramNum] = '';
                }
            }

            return $resolvedParams;
        } finally {
            $this->templateBridge->restoreExpanded();
        }
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

        // Normalize stdClass nests to arrays for Handlebars path walks.
        $encoded = json_encode($map);

        if (!is_string($encoded)) {
            return $map;
        }

        $decoded = json_decode($encoded, true);

        return is_array($decoded) ? $decoded : $map;
    }

    /**
     * Replace numbered placeholders ({{1}}, {{2}}, ...) in the template body
     * text with the resolved parameter values to produce human-readable content
     * for the Chatwoot conversation UI.
     */
    private function renderTemplateContent(string $templateBody, array $resolvedParams): string
    {
        if ($templateBody === '') {
            return '';
        }

        $content = $templateBody;

        foreach ($resolvedParams as $num => $value) {
            $content = str_replace('{{' . $num . '}}', $value, $content);
        }

        return $content;
    }

    /**
     * Detect transient (retryable) failures: HTTP 429, 5xx, connection timeouts.
     */
    private function isTransientFailure(string $errorMessage): bool
    {
        if (str_contains($errorMessage, 'HTTP 429')) {
            return true;
        }

        if (preg_match('/HTTP 5\d{2}/', $errorMessage)) {
            return true;
        }

        if (
            str_contains($errorMessage, 'Connection timed out')
            || str_contains($errorMessage, 'cURL error 28')
            || str_contains($errorMessage, 'Operation timed out')
        ) {
            return true;
        }

        return false;
    }

    /**
     * Mark a campaign as failed due to an infrastructure-level error
     * and fail all its pending contacts with the reason.
     */
    private function failCampaign(string $campaignId, string $reason): void
    {
        $this->log->error("ProcessWhatsAppCampaignChunk: Campaign {$campaignId} failed: {$reason}");

        $campaign = $this->entityManager->getEntityById('WhatsAppCampaign', $campaignId);

        if ($campaign && in_array($campaign->get('status'), ['Sending', 'Scheduled'])) {
            $campaign->set([
                'status' => 'Cancelled',
                'completedAt' => date('Y-m-d H:i:s'),
            ]);
            $this->entityManager->saveEntity($campaign);
        }

        $pendingContacts = $this->entityManager
            ->getRDBRepository('WhatsAppCampaignContact')
            ->where([
                'whatsAppCampaignId' => $campaignId,
                'status' => 'Pending',
            ])
            ->find();

        foreach ($pendingContacts as $contact) {
            $contact->set([
                'status' => 'Failed',
                'failedAt' => date('Y-m-d H:i:s'),
                'failedReason' => $reason,
                'opportunityAttributionStatus' => 'NotRequested',
            ]);
            $this->entityManager->saveEntity($contact);
            $this->incrementCampaignCounter($campaignId, 'failedCount');
        }
    }
}

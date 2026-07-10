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
use Espo\Core\Htmlizer\TemplateRendererFactory;
use Espo\Core\Job\Job;
use Espo\Core\Job\Job\Data;
use Espo\Core\Utils\Log;
use Espo\Modules\Chatwoot\Services\ChatwootApiClient;
use Espo\Modules\Chatwoot\Services\WhatsAppOptOutService;
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
            // Legacy campaigns without an explicit inbox: first active Cloud API inbox.
            $whatsappInbox = $this->entityManager
                ->getRDBRepository('ChatwootInbox')
                ->where([
                    'chatwootAccountId' => $chatwootAccountId,
                    'channelType' => ['whatsappCloudApi', 'whatsappCoexistence'],
                ])
                ->findOne();
        }

        $inboxId = $whatsappInbox ? $whatsappInbox->get('chatwootInboxId') : null;

        if (!$inboxId) {
            $this->failCampaign(
                $campaignId,
                "No WhatsApp Cloud API inbox found for campaign (select an active Meta Cloud API inbox)."
            );
            return;
        }

        $templateName = $campaign->get('templateName');
        $templateLanguage = $campaign->get('templateLanguage');
        $templateCategory = $campaign->get('templateCategory') ?: 'UTILITY';
        $templateBody = $campaign->get('templateBody') ?: '';
        $headerMediaUrl = $campaign->get('headerMediaUrl') ?: null;
        $headerMediaType = $campaign->get('headerMediaType') ?: null;

        $parameterMapping = $campaign->get('parameterMapping');
        if ($parameterMapping instanceof \stdClass) {
            $parameterMapping = (array) $parameterMapping;
        } elseif (is_string($parameterMapping)) {
            $parameterMapping = json_decode($parameterMapping, true);
        }
        if (!is_array($parameterMapping)) {
            $parameterMapping = [];
        }

        $sendContext = [
            'campaignId' => $campaignId,
            'platformUrl' => $platformUrl,
            'accountApiKey' => $accountApiKey,
            'chatwootAccountIdExternal' => $chatwootAccountIdExternal,
            'inboxId' => $inboxId,
            'templateName' => $templateName,
            'templateLanguage' => $templateLanguage,
            'templateCategory' => $templateCategory,
            'templateBody' => $templateBody,
            'headerMediaUrl' => $headerMediaUrl,
            'headerMediaType' => $headerMediaType,
            'parameterMapping' => $parameterMapping,
        ];

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

            try {
                if ($processedCount > 0) {
                    usleep(self::RATE_LIMIT_DELAY_MS * 1000);
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

                $campaignContact->set([
                    'status' => 'Sent',
                    'chatwootMessageId' => (string) ($result['message_id'] ?? ''),
                    'chatwootConversationId' => (string) ($result['conversation_id'] ?? ''),
                    'sentAt' => date('Y-m-d H:i:s'),
                    'processedParams' => $params,
                ]);
                $this->entityManager->saveEntity($campaignContact);

                $this->incrementCampaignCounter($campaignId, 'sentCount');
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

            $processedCount++;
        }

        return $processedCount;
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
     * Campaigns with continuous enrollment enabled are never auto-completed;
     * they stay in Sending until enrollment is stopped or the campaign is aborted.
     *
     * @param string $campaignId Campaign entity ID
     */
    private function checkCampaignCompletion(string $campaignId): void
    {
        $pendingOrRetryCount = $this->entityManager
            ->getRDBRepository('WhatsAppCampaignContact')
            ->where([
                'whatsAppCampaignId' => $campaignId,
                'status' => ['Pending', 'Retry'],
            ])
            ->count();

        if ($pendingOrRetryCount === 0) {
            $campaign = $this->entityManager->getEntityById('WhatsAppCampaign', $campaignId);

            if (
                $campaign &&
                $campaign->get('status') === 'Sending' &&
                !$campaign->get('continuousEnrollment')
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

        $resolvedParams = [];

        foreach ($parameterMapping as $paramNum => $expression) {
            try {
                $resolved = $renderer->renderTemplate($expression);
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
            ]);
            $this->entityManager->saveEntity($contact);
            $this->incrementCampaignCounter($campaignId, 'failedCount');
        }
    }
}

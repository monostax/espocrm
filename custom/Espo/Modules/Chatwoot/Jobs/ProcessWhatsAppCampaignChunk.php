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

        $this->log->info("ProcessWhatsAppCampaignChunk: Starting chunk at offset {$chunkOffset} for campaign {$campaignId}");

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

        $whatsappInbox = $this->entityManager
            ->getRDBRepository('ChatwootInbox')
            ->where([
                'chatwootAccountId' => $chatwootAccountId,
                'channelType' => 'Channel::Whatsapp',
            ])
            ->findOne();

        $inboxId = $whatsappInbox ? $whatsappInbox->get('chatwootInboxId') : null;

        if (!$inboxId) {
            $this->failCampaign($campaignId, "No WhatsApp Cloud API inbox (Channel::Whatsapp) found for Chatwoot account.");
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
        $contacts = $this->entityManager
            ->getRDBRepository('WhatsAppCampaignContact')
            ->where([
                'whatsAppCampaignId' => $campaignId,
                'status' => 'Pending',
            ])
            ->order('createdAt')
            ->limit($chunkOffset, $chunkSize)
            ->find();

        $processedCount = $this->processContacts($contacts, $sendContext);

        $this->log->info("ProcessWhatsAppCampaignChunk: First pass at offset {$chunkOffset} for campaign {$campaignId} ({$processedCount} contacts).");

        // --- Retry passes: re-process contacts marked as Retry ---
        for ($retryPass = 1; $retryPass <= self::MAX_RETRIES; $retryPass++) {
            $retryContacts = $this->entityManager
                ->getRDBRepository('WhatsAppCampaignContact')
                ->where([
                    'whatsAppCampaignId' => $campaignId,
                    'status' => 'Retry',
                ])
                ->order('createdAt')
                ->limit($chunkOffset, $chunkSize)
                ->find();

            $retryCount = count($retryContacts);

            if ($retryCount === 0) {
                break;
            }

            $this->log->info("ProcessWhatsAppCampaignChunk: Retry pass {$retryPass} for campaign {$campaignId} ({$retryCount} contacts).");

            sleep(self::RETRY_BACKOFF_SECONDS);

            $this->processContacts($retryContacts, $sendContext);
        }

        // --- Finalize any contacts still in Retry after all passes ---
        $this->finalizeRemainingRetries($campaignId, $chunkOffset, $chunkSize);

        $this->log->info("ProcessWhatsAppCampaignChunk: Completed chunk at offset {$chunkOffset} for campaign {$campaignId}.");

        $this->verifyMessageStatuses(
            $campaignId,
            $platformUrl,
            $accountApiKey,
            $chatwootAccountIdExternal
        );

        $this->checkCampaignCompletion($campaignId);
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
     */
    private function finalizeRemainingRetries(string $campaignId, int $chunkOffset, int $chunkSize): void
    {
        $remaining = $this->entityManager
            ->getRDBRepository('WhatsAppCampaignContact')
            ->where([
                'whatsAppCampaignId' => $campaignId,
                'status' => 'Retry',
            ])
            ->order('createdAt')
            ->limit($chunkOffset, $chunkSize)
            ->find();

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

            if ($campaign && $campaign->get('status') === 'Sending') {
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
     */
    private function verifyMessageStatuses(
        string $campaignId,
        string $platformUrl,
        string $accountApiKey,
        int $chatwootAccountId
    ): void {
        $sentContacts = $this->entityManager
            ->getRDBRepository('WhatsAppCampaignContact')
            ->where([
                'whatsAppCampaignId' => $campaignId,
                'status' => 'Sent',
            ])
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

                    if ($chatwootStatus === 'failed') {
                        $reason = $errorByMessageId[$msgId] ?? 'Delivery failed (detected via post-send verification)';

                        $contact->set([
                            'status' => 'Failed',
                            'failedAt' => date('Y-m-d H:i:s'),
                            'failedReason' => substr((string) $reason, 0, 5000),
                        ]);
                        $this->entityManager->saveEntity($contact);

                        $this->incrementCampaignCounter($campaignId, 'failedCount');
                        $this->decrementCampaignCounter($campaignId, 'sentCount');

                        if ($this->optOutService->isPermanentFailure((string) $reason)) {
                            $contactId = $contact->get('contactId');
                            if ($contactId) {
                                $this->optOutService->autoOptOutContact($contactId, $campaignId, (string) $reason);
                            }
                        }

                        $this->log->warning(
                            "ProcessWhatsAppCampaignChunk: Post-send verification detected failure " .
                            "for contact {$contact->getId()} (message {$msgId}): {$reason}"
                        );
                    }
                }
            } catch (\Throwable $e) {
                $this->log->warning(
                    "ProcessWhatsAppCampaignChunk: Post-send verification failed for conversation " .
                    "{$conversationId}: {$e->getMessage()}"
                );
            }
        }
    }

    /**
     * Decrement a campaign counter, ensuring it does not go below zero.
     */
    private function decrementCampaignCounter(string $campaignId, string $field): void
    {
        $campaign = $this->entityManager->getEntityById('WhatsAppCampaign', $campaignId);

        if ($campaign) {
            $currentValue = (int) $campaign->get($field);
            $campaign->set($field, max(0, $currentValue - 1));
            $this->entityManager->saveEntity($campaign);
        }
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

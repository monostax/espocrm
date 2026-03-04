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
     * 1000ms = 1 message per second = ~3600/hour.
     */
    private const RATE_LIMIT_DELAY_MS = 1000;

    public function __construct(
        private EntityManager $entityManager,
        private ChatwootApiClient $chatwootApiClient,
        private TemplateRendererFactory $templateRendererFactory,
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

        $parameterMapping = $campaign->get('parameterMapping');
        if (is_string($parameterMapping)) {
            $parameterMapping = json_decode($parameterMapping, true);
        }
        if (!is_array($parameterMapping)) {
            $parameterMapping = [];
        }

        $contacts = $this->entityManager
            ->getRDBRepository('WhatsAppCampaignContact')
            ->where([
                'whatsAppCampaignId' => $campaignId,
                'status' => 'Pending',
            ])
            ->order('createdAt')
            ->limit($chunkOffset, $chunkSize)
            ->find();

        $processedCount = 0;

        foreach ($contacts as $campaignContact) {
            if ($processedCount > 0 && $processedCount % 10 === 0) {
                $campaign = $this->entityManager->getEntityById('WhatsAppCampaign', $campaignId);
                if ($campaign && $campaign->get('status') === 'Cancelled') {
                    $this->log->info("ProcessWhatsAppCampaignChunk: Campaign {$campaignId} cancelled mid-chunk at offset {$chunkOffset}+{$processedCount}.");
                    return;
                }
            }

            try {
                if ($processedCount > 0) {
                    usleep(self::RATE_LIMIT_DELAY_MS * 1000);
                }

                $phoneNumber = $campaignContact->get('phoneNumber');
                $contactName = $campaignContact->get('contactName');

                $chatwootContact = $this->chatwootApiClient->findOrCreateContact(
                    $platformUrl,
                    $accountApiKey,
                    $chatwootAccountIdExternal,
                    $inboxId,
                    $phoneNumber,
                    $contactName
                );

                $chatwootContactId = $chatwootContact['id'] ?? null;

                if (!$chatwootContactId) {
                    throw new Error("Failed to get Chatwoot contact ID for phone {$phoneNumber}.");
                }

                $params = $this->resolveParameterMapping(
                    $parameterMapping,
                    $campaignContact->get('contactId')
                );

                $content = $this->renderTemplateContent($templateBody, $params);

                $result = $this->chatwootApiClient->sendTemplateMessage(
                    $platformUrl,
                    $accountApiKey,
                    $chatwootAccountIdExternal,
                    $chatwootContactId,
                    $inboxId,
                    $templateName,
                    $templateLanguage,
                    $params,
                    $templateCategory,
                    $content
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

                $processedCount++;
            } catch (\Exception $e) {
                $this->log->error("ProcessWhatsAppCampaignChunk: Failed to process contact {$campaignContact->getId()}: {$e->getMessage()}");

                $campaignContact->set([
                    'status' => 'Failed',
                    'failedAt' => date('Y-m-d H:i:s'),
                    'failedReason' => substr($e->getMessage(), 0, 5000),
                ]);
                $this->entityManager->saveEntity($campaignContact);

                $this->incrementCampaignCounter($campaignId, 'failedCount');

                $processedCount++;
            }
        }

        $this->log->info("ProcessWhatsAppCampaignChunk: Completed chunk at offset {$chunkOffset} for campaign {$campaignId} ({$processedCount} contacts).");

        // Check if campaign is complete
        $this->checkCampaignCompletion($campaignId);
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
        $pendingCount = $this->entityManager
            ->getRDBRepository('WhatsAppCampaignContact')
            ->where([
                'whatsAppCampaignId' => $campaignId,
                'status' => 'Pending',
            ])
            ->count();

        if ($pendingCount === 0) {
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

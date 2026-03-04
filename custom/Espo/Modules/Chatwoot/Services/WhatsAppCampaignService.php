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

namespace Espo\Modules\Chatwoot\Services;

use Espo\Core\Exceptions\Error;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Job\JobSchedulerFactory;
use Espo\Core\Utils\Log;
use Espo\Modules\FeatureMetaWhatsAppBusiness\Services\MetaGraphApiClient;
use Espo\ORM\EntityManager;

/**
 * Service for WhatsApp Campaign lifecycle management.
 *
 * Orchestrates template validation, audience resolution, job scheduling,
 * and campaign status management.
 */
class WhatsAppCampaignService
{
    private const CHUNK_SIZE = 50;

    public function __construct(
        private EntityManager $entityManager,
        private MetaGraphApiClient $metaGraphApiClient,
        private ChatwootApiClient $chatwootApiClient,
        private Log $log,
        private JobSchedulerFactory $jobSchedulerFactory
    ) {}

    /**
     * Validate a WhatsApp template exists and is approved.
     *
     * @param string $templateName Template name
     * @param string $language Template language code
     * @param string $accessToken Meta access token
     * @param string $wabaId WhatsApp Business Account ID
     * @return array<string, mixed> Template data
     * @throws Error
     */
    public function validateTemplate(
        string $templateName,
        string $language,
        string $accessToken,
        string $wabaId
    ): array {
        $template = $this->metaGraphApiClient->getTemplateByName(
            $accessToken,
            $wabaId,
            $templateName
        );

        if (!$template) {
            throw new Error("Template '{$templateName}' not found in WABA {$wabaId}.");
        }

        $status = $template['status'] ?? 'UNKNOWN';
        if ($status !== 'APPROVED') {
            throw new Error("Template '{$templateName}' is not approved (status: {$status}).");
        }

        $this->log->info("WhatsAppCampaignService: Template '{$templateName}' validated successfully (status: {$status})");

        return $template;
    }

    /**
     * Resolve the audience for a campaign.
     *
     * Merges contacts from TargetLists and manual contacts, filters opt-outs,
     * normalizes phone numbers, and removes duplicates.
     *
     * @param string $campaignId Campaign entity ID
     * @return array<int, array{contactId: string, phoneNumber: string, contactName: string}>
     * @throws Error
     */
    public function resolveAudience(string $campaignId): array
    {
        $campaign = $this->entityManager->getEntityById('WhatsAppCampaign', $campaignId);

        if (!$campaign) {
            throw new NotFound("Campaign {$campaignId} not found.");
        }

        $audience = [];
        $seenPhones = [];

        // 1. Collect contacts from TargetLists (filter opt-outs via ORM)
        $targetLists = $this->entityManager
            ->getRDBRepository('WhatsAppCampaign')
            ->getRelation($campaign, 'targetLists')
            ->find();

        foreach ($targetLists as $targetList) {
            $contacts = $this->entityManager
                ->getRDBRepository('TargetList')
                ->getRelation($targetList, 'contacts')
                ->where(['targetListContact.isOptedOut' => false])
                ->find();

            foreach ($contacts as $contact) {
                $phone = $this->normalizePhone($contact->get('phoneNumber'));

                if (!$phone) {
                    $this->log->warning("WhatsAppCampaignService: Contact {$contact->getId()} has no valid phone number, skipping.");
                    continue;
                }

                if (isset($seenPhones[$phone])) {
                    $this->log->debug("WhatsAppCampaignService: Duplicate phone {$phone} skipped (contact {$contact->getId()})");
                    continue;
                }

                $seenPhones[$phone] = true;
                $audience[] = [
                    'contactId' => $contact->getId(),
                    'phoneNumber' => $phone,
                    'contactName' => trim(($contact->get('firstName') ?? '') . ' ' . ($contact->get('lastName') ?? '')),
                ];
            }
        }

        // 2. Collect manual contacts
        $manualContacts = $this->entityManager
            ->getRDBRepository('WhatsAppCampaign')
            ->getRelation($campaign, 'manualContacts')
            ->find();

        foreach ($manualContacts as $contact) {
            $phone = $this->normalizePhone($contact->get('phoneNumber'));

            if (!$phone) {
                $this->log->warning("WhatsAppCampaignService: Manual contact {$contact->getId()} has no valid phone number, skipping.");
                continue;
            }

            if (isset($seenPhones[$phone])) {
                $this->log->debug("WhatsAppCampaignService: Duplicate manual phone {$phone} skipped (contact {$contact->getId()})");
                continue;
            }

            $seenPhones[$phone] = true;
            $audience[] = [
                'contactId' => $contact->getId(),
                'phoneNumber' => $phone,
                'contactName' => trim(($contact->get('firstName') ?? '') . ' ' . ($contact->get('lastName') ?? '')),
            ];
        }

        $this->log->info("WhatsAppCampaignService: Resolved audience of " . count($audience) . " contacts for campaign {$campaignId}");

        return $audience;
    }

    /**
     * Launch a WhatsApp campaign.
     *
     * Validates the campaign can be launched, resolves the audience,
     * creates junction records, and schedules chunk processing jobs.
     *
     * @param string $campaignId Campaign entity ID
     * @return \Espo\ORM\Entity Updated campaign entity
     * @throws Error
     * @throws Forbidden
     */
    public function launch(string $campaignId): \Espo\ORM\Entity
    {
        $campaign = $this->entityManager->getEntityById('WhatsAppCampaign', $campaignId);

        if (!$campaign) {
            throw new NotFound("Campaign {$campaignId} not found.");
        }

        if ($campaign->get('status') !== 'Draft') {
            throw new Forbidden("Campaign can only be launched from Draft status (current: {$campaign->get('status')}).");
        }

        // Validate required fields
        $chatwootAccountId = $campaign->get('chatwootAccountId');
        if (!$chatwootAccountId) {
            throw new Error('Campaign must have a Chatwoot Account linked.');
        }

        // Resolve audience
        $audience = $this->resolveAudience($campaignId);

        if (empty($audience)) {
            throw new Error('Campaign has no valid recipients. Check TargetLists and manual contacts.');
        }

        // Get Chatwoot account for API context
        $chatwootAccount = $this->entityManager->getEntityById('ChatwootAccount', $chatwootAccountId);
        if (!$chatwootAccount) {
            throw new Error('Linked Chatwoot Account not found.');
        }

        foreach ($audience as $item) {
            $this->entityManager->createEntity('WhatsAppCampaignContact', [
                'whatsAppCampaignId' => $campaignId,
                'contactId' => $item['contactId'],
                'chatwootAccountId' => $chatwootAccountId,
                'phoneNumber' => $item['phoneNumber'],
                'contactName' => $item['contactName'],
                'status' => 'Pending',
            ]);
        }

        // Update campaign counters
        $campaign->set([
            'status' => 'Sending',
            'totalRecipients' => count($audience),
            'startedAt' => date('Y-m-d H:i:s'),
        ]);
        $this->entityManager->saveEntity($campaign);

        // Schedule chunk processing jobs
        $totalContacts = count($audience);
        $totalChunks = (int) ceil($totalContacts / self::CHUNK_SIZE);

        for ($i = 0; $i < $totalChunks; $i++) {
            $jobScheduler = $this->jobSchedulerFactory->create();

            $jobScheduler
                ->setClassName('Espo\\Modules\\Chatwoot\\Jobs\\ProcessWhatsAppCampaignChunk')
                ->setData([
                    'campaignId' => $campaignId,
                    'chunkOffset' => $i * self::CHUNK_SIZE,
                    'chunkSize' => self::CHUNK_SIZE,
                ])
                ->schedule();
        }

        $this->log->info("WhatsAppCampaignService: Launched campaign {$campaignId} with {$totalContacts} recipients in {$totalChunks} chunks.");

        return $campaign;
    }

    /**
     * Abort a running or scheduled campaign.
     *
     * Sets the campaign status to Cancelled. Remaining chunk jobs will
     * check the status and skip processing.
     *
     * @param string $campaignId Campaign entity ID
     * @return \Espo\ORM\Entity Updated campaign entity
     * @throws Error
     */
    public function abort(string $campaignId): \Espo\ORM\Entity
    {
        $campaign = $this->entityManager->getEntityById('WhatsAppCampaign', $campaignId);

        if (!$campaign) {
            throw new NotFound("Campaign {$campaignId} not found.");
        }

        $status = $campaign->get('status');
        if (!in_array($status, ['Sending', 'Scheduled'])) {
            throw new Forbidden("Campaign can only be aborted from Sending or Scheduled status (current: {$status}).");
        }

        $campaign->set([
            'status' => 'Cancelled',
            'completedAt' => date('Y-m-d H:i:s'),
        ]);
        $this->entityManager->saveEntity($campaign);

        $this->log->info("WhatsAppCampaignService: Aborted campaign {$campaignId}.");

        return $campaign;
    }

    /**
     * Get campaign statistics by aggregating from junction records.
     *
     * @param string $campaignId Campaign entity ID
     * @return array<string, int>
     */
    public function getCampaignStats(string $campaignId): array
    {
        $statuses = ['Pending', 'Sent', 'Delivered', 'Read', 'Failed', 'OptedOut', 'Bounced', 'Blocked'];
        $stats = [];

        foreach ($statuses as $status) {
            $count = $this->entityManager
                ->getRDBRepository('WhatsAppCampaignContact')
                ->where([
                    'whatsAppCampaignId' => $campaignId,
                    'status' => $status,
                ])
                ->count();

            $stats[lcfirst($status) . 'Count'] = $count;
        }

        return $stats;
    }

    /**
     * Normalize a phone number to E.164 format for Brazilian numbers.
     *
     * Handles formats: (11) 98765-4321, 11987654321, +5511987654321
     *
     * @param string|null $phone Raw phone number
     * @return string|null Normalized phone or null if invalid
     */
    private function normalizePhone(?string $phone): ?string
    {
        if (!$phone) {
            return null;
        }

        // Remove all non-digit characters
        $digits = preg_replace('/\D/', '', $phone);

        if (!$digits || strlen($digits) < 10) {
            return null;
        }

        // If starts with 55 and has 12-13 digits, it's already Brazilian E.164 (without +)
        if (str_starts_with($digits, '55') && strlen($digits) >= 12 && strlen($digits) <= 13) {
            return '+' . $digits;
        }

        // If 10-11 digits, assume Brazilian local number
        if (strlen($digits) >= 10 && strlen($digits) <= 11) {
            return '+55' . $digits;
        }

        // Already has country code (other countries)
        if (strlen($digits) > 11) {
            return '+' . $digits;
        }

        return null;
    }
}

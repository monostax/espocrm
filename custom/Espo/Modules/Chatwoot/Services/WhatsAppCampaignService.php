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
     * Merges contacts from TargetLists and manual contacts, filters opt-outs
     * (both per-TargetList and global whatsAppOptedOut), normalizes phone
     * numbers, removes duplicates, and applies campaign/list exclusions.
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
        $whatsAppOptedOutCount = 0;

        // 1. Collect contacts from TargetLists (filter per-list opt-outs and global whatsAppOptedOut)
        $targetLists = $this->entityManager
            ->getRDBRepository('WhatsAppCampaign')
            ->getRelation($campaign, 'targetLists')
            ->find();

        foreach ($targetLists as $targetList) {
            $contacts = $this->entityManager
                ->getRDBRepository('TargetList')
                ->getRelation($targetList, 'contacts')
                ->where(['@relation.optedOut' => false])
                ->find();

            foreach ($contacts as $contact) {
                if ($contact->get('whatsAppOptedOut')) {
                    $whatsAppOptedOutCount++;
                    continue;
                }

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

        // 2. Collect manual contacts (also filter whatsAppOptedOut)
        $manualContacts = $this->entityManager
            ->getRDBRepository('WhatsAppCampaign')
            ->getRelation($campaign, 'manualContacts')
            ->find();

        foreach ($manualContacts as $contact) {
            if ($contact->get('whatsAppOptedOut')) {
                $whatsAppOptedOutCount++;
                continue;
            }

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

        if ($whatsAppOptedOutCount > 0) {
            $this->log->info("WhatsAppCampaignService: Skipped {$whatsAppOptedOutCount} contacts with whatsAppOptedOut flag.");
        }

        $audienceBeforeExclusions = count($audience);

        // 3. Exclude recipients from previous campaigns
        $audience = $this->applyExcludeCampaigns($campaign, $audience);

        // 4. Exclude recipients from excluding target lists
        $audience = $this->applyExcludingTargetLists($campaign, $audience);

        $excludedCount = $audienceBeforeExclusions - count($audience);
        if ($excludedCount > 0) {
            $this->log->info("WhatsAppCampaignService: Excluded {$excludedCount} contacts via campaign/list exclusions.");
        }

        $this->log->info("WhatsAppCampaignService: Resolved audience of " . count($audience) . " contacts for campaign {$campaignId}");

        return $audience;
    }

    /**
     * Remove contacts that were successfully reached in linked exclude campaigns.
     *
     * @param \Espo\ORM\Entity $campaign
     * @param array<int, array{contactId: string, phoneNumber: string, contactName: string}> $audience
     * @return array<int, array{contactId: string, phoneNumber: string, contactName: string}>
     */
    private function applyExcludeCampaigns(\Espo\ORM\Entity $campaign, array $audience): array
    {
        $excludeCampaigns = $this->entityManager
            ->getRDBRepository('WhatsAppCampaign')
            ->getRelation($campaign, 'excludeCampaigns')
            ->find();

        $excludeCampaignIds = [];
        foreach ($excludeCampaigns as $ec) {
            $excludeCampaignIds[] = $ec->getId();
        }

        if (empty($excludeCampaignIds)) {
            return $audience;
        }

        $reachedStatuses = ['Sent', 'Delivered', 'Read', 'Replied'];
        $excludedPhones = [];

        $excludedContacts = $this->entityManager
            ->getRDBRepository('WhatsAppCampaignContact')
            ->where([
                'whatsAppCampaignId' => $excludeCampaignIds,
                'status' => $reachedStatuses,
            ])
            ->select(['phoneNumber'])
            ->group(['phoneNumber'])
            ->find();

        foreach ($excludedContacts as $ec) {
            $excludedPhones[$ec->get('phoneNumber')] = true;
        }

        if (empty($excludedPhones)) {
            return $audience;
        }

        return array_values(array_filter($audience, function ($item) use ($excludedPhones) {
            return !isset($excludedPhones[$item['phoneNumber']]);
        }));
    }

    /**
     * Remove contacts that appear in linked excluding target lists.
     *
     * @param \Espo\ORM\Entity $campaign
     * @param array<int, array{contactId: string, phoneNumber: string, contactName: string}> $audience
     * @return array<int, array{contactId: string, phoneNumber: string, contactName: string}>
     */
    private function applyExcludingTargetLists(\Espo\ORM\Entity $campaign, array $audience): array
    {
        $excludingLists = $this->entityManager
            ->getRDBRepository('WhatsAppCampaign')
            ->getRelation($campaign, 'excludingTargetLists')
            ->find();

        $excludedPhones = [];

        foreach ($excludingLists as $targetList) {
            $contacts = $this->entityManager
                ->getRDBRepository('TargetList')
                ->getRelation($targetList, 'contacts')
                ->find();

            foreach ($contacts as $contact) {
                $phone = $this->normalizePhone($contact->get('phoneNumber'));
                if ($phone) {
                    $excludedPhones[$phone] = true;
                }
            }
        }

        if (empty($excludedPhones)) {
            return $audience;
        }

        return array_values(array_filter($audience, function ($item) use ($excludedPhones) {
            return !isset($excludedPhones[$item['phoneNumber']]);
        }));
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

        // Prefer explicit inbox; account is derived from it (or set for legacy rows).
        if (!$campaign->get('chatwootInboxId') && !$campaign->get('chatwootAccountId')) {
            throw new Error('Campaign must have a WhatsApp Inbox selected (Meta Cloud API).');
        }

        $chatwootAccountId = $campaign->get('chatwootAccountId');
        if (!$chatwootAccountId) {
            throw new Error('Campaign must have a Chatwoot Account linked (select a WhatsApp Inbox).');
        }

        // Resolve audience
        $audience = $this->resolveAudience($campaignId);

        if (empty($audience) && !$campaign->get('continuousEnrollment')) {
            throw new Error('Campaign has no valid recipients. Check TargetLists and manual contacts.');
        }

        // Get Chatwoot account for API context
        $chatwootAccount = $this->entityManager->getEntityById('ChatwootAccount', $chatwootAccountId);
        if (!$chatwootAccount) {
            throw new Error('Linked Chatwoot Account not found.');
        }

        // Sync WhatsApp templates from Meta so Chatwoot has the latest versions
        $platform = $this->entityManager->getEntityById('ChatwootPlatform', $chatwootAccount->get('platformId'));

        if ($platform) {
            $whatsappInbox = $this->resolveCampaignInbox($campaign, $chatwootAccountId);

            if ($whatsappInbox) {
                $this->chatwootApiClient->syncInboxTemplates(
                    $platform->get('backendUrl'),
                    $chatwootAccount->get('apiKey'),
                    (int) $chatwootAccount->get('chatwootAccountId'),
                    (int) $whatsappInbox->get('chatwootInboxId')
                );
            }
        }

        $createdIds = $this->createCampaignContacts($campaign, $audience);

        // Update campaign counters
        $campaign->set([
            'status' => 'Sending',
            'totalRecipients' => count($createdIds),
            'startedAt' => date('Y-m-d H:i:s'),
        ]);
        $this->entityManager->saveEntity($campaign);

        // Schedule chunk processing jobs (explicit ID lists; safe for later enrollments)
        $totalContacts = count($createdIds);
        $totalChunks = $this->scheduleChunkJobs($campaignId, $createdIds);

        $this->log->info("WhatsAppCampaignService: Launched campaign {$campaignId} with {$totalContacts} recipients in {$totalChunks} chunks.");

        return $campaign;
    }

    /**
     * Enroll newly added contacts into a running campaign.
     *
     * Re-resolves the audience (target lists + manual contacts, with all
     * opt-out and exclusion guards) and enrolls only contacts not already
     * present in the campaign. Used by the continuous-enrollment sweep job.
     *
     * Idempotent: dedupes by contactId and phoneNumber against existing
     * WhatsAppCampaignContact rows; the unique DB index
     * (contactId, whatsAppCampaignId) guards against races.
     *
     * @param string $campaignId Campaign entity ID
     * @return int Number of newly enrolled contacts
     * @throws NotFound
     */
    public function enrollNewContacts(string $campaignId): int
    {
        $campaign = $this->entityManager->getEntityById('WhatsAppCampaign', $campaignId);

        if (!$campaign) {
            throw new NotFound("Campaign {$campaignId} not found.");
        }

        if ($campaign->get('status') !== 'Sending' || !$campaign->get('continuousEnrollment')) {
            return 0;
        }

        $audience = $this->resolveAudience($campaignId);

        if (empty($audience)) {
            return 0;
        }

        // Diff against already-enrolled rows (by contactId and phoneNumber).
        $existingRows = $this->entityManager
            ->getRDBRepository('WhatsAppCampaignContact')
            ->where(['whatsAppCampaignId' => $campaignId])
            ->select(['id', 'contactId', 'phoneNumber'])
            ->find();

        $enrolledContactIds = [];
        $enrolledPhones = [];

        foreach ($existingRows as $row) {
            if ($row->get('contactId')) {
                $enrolledContactIds[$row->get('contactId')] = true;
            }
            if ($row->get('phoneNumber')) {
                $enrolledPhones[$row->get('phoneNumber')] = true;
            }
        }

        $newAudience = array_values(array_filter(
            $audience,
            function ($item) use ($enrolledContactIds, $enrolledPhones) {
                return !isset($enrolledContactIds[$item['contactId']])
                    && !isset($enrolledPhones[$item['phoneNumber']]);
            }
        ));

        if (empty($newAudience)) {
            return 0;
        }

        $createdIds = $this->createCampaignContacts($campaign, $newAudience);

        if (empty($createdIds)) {
            return 0;
        }

        $totalRows = $this->entityManager
            ->getRDBRepository('WhatsAppCampaignContact')
            ->where(['whatsAppCampaignId' => $campaignId])
            ->count();

        $campaign->set(['totalRecipients' => $totalRows]);
        $this->entityManager->saveEntity($campaign);

        $this->scheduleChunkJobs($campaignId, $createdIds);

        $this->log->info(
            "WhatsAppCampaignService: Enrolled " . count($createdIds) .
            " new contacts into campaign {$campaignId}."
        );

        return count($createdIds);
    }

    /**
     * Stop continuous enrollment on a running campaign.
     *
     * Disables the flag; if no Pending/Retry recipients remain, the campaign
     * is completed immediately. Otherwise the remaining chunk jobs will
     * complete it once they finish.
     *
     * @param string $campaignId Campaign entity ID
     * @return \Espo\ORM\Entity Updated campaign entity
     * @throws Forbidden
     * @throws NotFound
     */
    public function stopEnrollment(string $campaignId): \Espo\ORM\Entity
    {
        $campaign = $this->entityManager->getEntityById('WhatsAppCampaign', $campaignId);

        if (!$campaign) {
            throw new NotFound("Campaign {$campaignId} not found.");
        }

        if ($campaign->get('status') !== 'Sending' || !$campaign->get('continuousEnrollment')) {
            throw new Forbidden('Campaign is not a running campaign with continuous enrollment enabled.');
        }

        $campaign->set('continuousEnrollment', false);

        $pendingOrRetryCount = $this->entityManager
            ->getRDBRepository('WhatsAppCampaignContact')
            ->where([
                'whatsAppCampaignId' => $campaignId,
                'status' => ['Pending', 'Retry'],
            ])
            ->count();

        if ($pendingOrRetryCount === 0) {
            $campaign->set([
                'status' => 'Completed',
                'completedAt' => date('Y-m-d H:i:s'),
            ]);
        }

        $this->entityManager->saveEntity($campaign);

        $this->log->info("WhatsAppCampaignService: Stopped enrollment for campaign {$campaignId}.");

        return $campaign;
    }

    /**
     * Create Pending WhatsAppCampaignContact rows for an audience.
     *
     * Duplicate enrollments (unique index on contactId + whatsAppCampaignId)
     * are logged and skipped, making this safe under concurrent sweeps.
     *
     * @param \Espo\ORM\Entity $campaign
     * @param array<int, array{contactId: string, phoneNumber: string, contactName: string}> $audience
     * @return string[] Created WhatsAppCampaignContact IDs
     */
    private function createCampaignContacts(\Espo\ORM\Entity $campaign, array $audience): array
    {
        $createdIds = [];

        foreach ($audience as $item) {
            try {
                $entity = $this->entityManager->createEntity('WhatsAppCampaignContact', [
                    'whatsAppCampaignId' => $campaign->getId(),
                    'contactId' => $item['contactId'],
                    'chatwootAccountId' => $campaign->get('chatwootAccountId'),
                    'phoneNumber' => $item['phoneNumber'],
                    'contactName' => $item['contactName'],
                    'status' => 'Pending',
                ]);

                $createdIds[] = $entity->getId();
            } catch (\Throwable $e) {
                $this->log->warning(
                    "WhatsAppCampaignService: Skipped enrolling contact {$item['contactId']} " .
                    "into campaign {$campaign->getId()}: {$e->getMessage()}"
                );
            }
        }

        return $createdIds;
    }

    /**
     * Schedule chunk processing jobs for a set of campaign contact rows.
     *
     * Each job receives an explicit ID list (not offset paging), so jobs
     * scheduled by later enrollments cannot interfere with earlier ones.
     *
     * @param string $campaignId Campaign entity ID
     * @param string[] $campaignContactIds WhatsAppCampaignContact IDs
     * @return int Number of scheduled chunks
     */
    private function scheduleChunkJobs(string $campaignId, array $campaignContactIds): int
    {
        $chunks = array_chunk($campaignContactIds, self::CHUNK_SIZE);

        foreach ($chunks as $chunk) {
            $this->jobSchedulerFactory
                ->create()
                ->setClassName('Espo\\Modules\\Chatwoot\\Jobs\\ProcessWhatsAppCampaignChunk')
                ->setData([
                    'campaignId' => $campaignId,
                    'campaignContactIds' => $chunk,
                ])
                ->schedule();
        }

        return count($chunks);
    }

    /**
     * Resolve the ChatwootInbox used for a campaign send path.
     *
     * Prefers the explicitly selected inbox; falls back to first Cloud API /
     * Coexistence Integration inbox on the account for legacy campaigns.
     *
     * @return \Espo\ORM\Entity|null ChatwootInbox entity
     */
    public function resolveCampaignInbox(\Espo\ORM\Entity $campaign, string $chatwootAccountId): ?\Espo\ORM\Entity
    {
        $selectedInboxId = $campaign->get('chatwootInboxId');

        if ($selectedInboxId) {
            $inbox = $this->entityManager->getEntityById('ChatwootInbox', $selectedInboxId);

            if ($inbox && $inbox->get('chatwootAccountId') === $chatwootAccountId) {
                return $inbox;
            }
        }

        return $this->entityManager
            ->getRDBRepository('ChatwootInbox')
            ->where([
                'chatwootAccountId' => $chatwootAccountId,
                'channelType' => ['whatsappCloudApi', 'whatsappCoexistence'],
            ])
            ->findOne();
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

        // Brazilian E.164: 55 + 2-digit DDD + 9-digit mobile (13 digits total)
        // Old format with 8-digit mobile (12 digits) needs the "9" prefix added.
        if (str_starts_with($digits, '55') && strlen($digits) >= 12 && strlen($digits) <= 13) {
            if (strlen($digits) === 12) {
                $ddd = substr($digits, 2, 2);
                $local = substr($digits, 4);
                // Local starts with 6-9 = mobile in old format, add the "9" prefix
                if (preg_match('/^[6-9]/', $local)) {
                    $digits = '55' . $ddd . '9' . $local;
                }
            }
            return '+' . $digits;
        }

        // If 10-11 digits, assume Brazilian local number (DDD + local)
        if (strlen($digits) >= 10 && strlen($digits) <= 11) {
            if (strlen($digits) === 10) {
                $ddd = substr($digits, 0, 2);
                $local = substr($digits, 2);
                if (preg_match('/^[6-9]/', $local)) {
                    $digits = $ddd . '9' . $local;
                }
            }
            return '+55' . $digits;
        }

        // Already has country code (other countries)
        if (strlen($digits) > 11) {
            return '+' . $digits;
        }

        return null;
    }
}

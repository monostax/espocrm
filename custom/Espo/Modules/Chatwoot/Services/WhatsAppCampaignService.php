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
use Espo\Entities\PhoneNumber;
use Espo\Modules\FeatureMetaWhatsAppBusiness\Services\MetaGraphApiClient;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\Repositories\PhoneNumber as PhoneNumberRepository;

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
        private JobSchedulerFactory $jobSchedulerFactory,
        private WhatsAppCampaignOpportunityService $opportunityService,
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
     * @return array<int, array{contactId: string, phoneNumber: string, contactName: string, targetListIds: string[]}>
     * @throws Error
     */
    public function resolveAudience(string $campaignId): array
    {
        $campaign = $this->entityManager->getEntityById('WhatsAppCampaign', $campaignId);

        if (!$campaign) {
            throw new NotFound("Campaign {$campaignId} not found.");
        }

        $targetLists = $this->entityManager
            ->getRDBRepository('WhatsAppCampaign')
            ->getRelation($campaign, 'targetLists')
            ->find();

        $manualContacts = $this->entityManager
            ->getRDBRepository('WhatsAppCampaign')
            ->getRelation($campaign, 'manualContacts')
            ->find();

        $excludeCampaigns = $this->entityManager
            ->getRDBRepository('WhatsAppCampaign')
            ->getRelation($campaign, 'excludeCampaigns')
            ->find();

        $excludeCampaignIds = [];
        foreach ($excludeCampaigns as $ec) {
            $excludeCampaignIds[] = $ec->getId();
        }

        $excludingTargetLists = $this->entityManager
            ->getRDBRepository('WhatsAppCampaign')
            ->getRelation($campaign, 'excludingTargetLists')
            ->find();

        $audience = $this->resolveAudienceFromSources(
            $targetLists,
            $manualContacts,
            $excludeCampaignIds,
            $excludingTargetLists
        );

        $this->log->info("WhatsAppCampaignService: Resolved audience of " . count($audience) . " contacts for campaign {$campaignId}");

        return $audience;
    }

    /**
     * Resolve an audience from explicit sources (audience layer, campaign-agnostic).
     *
     * Merges contacts from TargetLists and manual contacts, filters opt-outs
     * (both per-TargetList and global whatsAppOptedOut), normalizes phone
     * numbers, removes duplicates, and applies campaign/list exclusions.
     *
     * Used by both per-campaign audience resolution and campaign
     * distributions (allocation layer).
     *
     * @param iterable<\Espo\ORM\Entity> $targetLists TargetList entities
     * @param iterable<\Espo\ORM\Entity> $manualContacts Contact entities
     * @param string[] $excludeCampaignIds WhatsAppCampaign IDs whose reached recipients are excluded
     * @param iterable<\Espo\ORM\Entity> $excludingTargetLists TargetList entities whose members are excluded
     * @return array<int, array{contactId: string, phoneNumber: string, contactName: string, targetListIds: string[]}>
     */
    public function resolveAudienceFromSources(
        iterable $targetLists,
        iterable $manualContacts,
        array $excludeCampaignIds,
        iterable $excludingTargetLists
    ): array {
        $audience = [];
        $audienceIndexByPhone = [];
        $ambiguousPhones = [];
        $phonesByContactId = [];
        $whatsAppOptedOutCount = 0;

        foreach ($targetLists as $targetList) {
            $targetListId = (string) $targetList->getId();
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

                $this->accumulateContactPhones(
                    $contact,
                    $targetListId,
                    $audience,
                    $audienceIndexByPhone,
                    $ambiguousPhones,
                    $phonesByContactId
                );
            }
        }

        foreach ($manualContacts as $contact) {
            if ($contact->get('whatsAppOptedOut')) {
                $whatsAppOptedOutCount++;
                continue;
            }

            $this->accumulateContactPhones(
                $contact,
                null,
                $audience,
                $audienceIndexByPhone,
                $ambiguousPhones,
                $phonesByContactId
            );
        }

        if ($whatsAppOptedOutCount > 0) {
            $this->log->info("WhatsAppCampaignService: Skipped {$whatsAppOptedOutCount} contacts with whatsAppOptedOut flag.");
        }

        if ($ambiguousPhones !== []) {
            $audience = array_values(array_filter(
                $audience,
                static fn (array $item): bool => !isset($ambiguousPhones[$item['phoneNumber']])
            ));
        }

        $audienceBeforeExclusions = count($audience);

        $audience = $this->applyExcludeCampaigns($excludeCampaignIds, $audience);
        $audience = $this->applyExcludingTargetLists($excludingTargetLists, $audience);

        $excludedCount = $audienceBeforeExclusions - count($audience);
        if ($excludedCount > 0) {
            $this->log->info("WhatsAppCampaignService: Excluded {$excludedCount} contacts via campaign/list exclusions.");
        }

        foreach ($audience as &$item) {
            $item['targetListIds'] = array_values(array_unique($item['targetListIds']));
            sort($item['targetListIds']);
        }
        unset($item);

        return $audience;
    }

    /**
     * @param array<int, array{contactId: string, phoneNumber: string, contactName: string, targetListIds: string[]}> $audience
     * @param array<string, int> $audienceIndexByPhone
     * @param array<string, true> $ambiguousPhones
     * @param array<string, string[]> $phonesByContactId
     */
    private function accumulateContactPhones(
        Entity $contact,
        ?string $targetListId,
        array &$audience,
        array &$audienceIndexByPhone,
        array &$ambiguousPhones,
        array &$phonesByContactId
    ): void {
        $phones = $this->getSendablePhonesForContact($contact, $phonesByContactId);

        if ($phones === []) {
            $this->log->warning(
                "WhatsAppCampaignService: Contact {$contact->getId()} has no valid phone number, skipping."
            );

            return;
        }

        $contactName = trim(($contact->get('firstName') ?? '') . ' ' . ($contact->get('lastName') ?? ''));

        foreach ($phones as $phone) {
            if (isset($audienceIndexByPhone[$phone])) {
                $index = $audienceIndexByPhone[$phone];

                if ($audience[$index]['contactId'] !== $contact->getId()) {
                    $ambiguousPhones[$phone] = true;
                    $this->log->warning(
                        "WhatsAppCampaignService: Phone {$phone} belongs to multiple Contacts; " .
                        "excluding it because recipient and Target List provenance are ambiguous."
                    );

                    continue;
                }

                if ($targetListId !== null) {
                    $audience[$index]['targetListIds'][] = $targetListId;
                }

                continue;
            }

            $audienceIndexByPhone[$phone] = count($audience);
            $audience[] = [
                'contactId' => $contact->getId(),
                'phoneNumber' => $phone,
                'contactName' => $contactName,
                'targetListIds' => $targetListId !== null ? [$targetListId] : [],
            ];
        }
    }

    /**
     * All sendable phone numbers on the Contact (primary + secondary),
     * skipping opted-out / invalid / Fax. One recipient row per number.
     *
     * @param array<string, string[]> $phonesByContactId
     * @return string[]
     */
    private function getSendablePhonesForContact(Entity $contact, array &$phonesByContactId): array
    {
        $contactId = (string) $contact->getId();

        if (isset($phonesByContactId[$contactId])) {
            return $phonesByContactId[$contactId];
        }

        $phones = [];
        $seen = [];

        /** @var PhoneNumberRepository $repo */
        $repo = $this->entityManager->getRepository(PhoneNumber::ENTITY_TYPE);

        foreach ($repo->getPhoneNumberData($contact) as $row) {
            if (!empty($row->optOut) || !empty($row->invalid)) {
                continue;
            }

            if (strcasecmp((string) ($row->type ?? ''), 'Fax') === 0) {
                continue;
            }

            $phone = $this->normalizePhone($row->phoneNumber ?? null);

            if ($phone === null || isset($seen[$phone])) {
                continue;
            }

            $seen[$phone] = true;
            $phones[] = $phone;
        }

        if ($phones === []) {
            $primary = $this->normalizePhone($contact->get('phoneNumber'));

            if ($primary !== null) {
                $phones[] = $primary;
            }
        }

        return $phonesByContactId[$contactId] = $phones;
    }

    /**
     * Every phone on the Contact (including opted-out / invalid) for exclusion matching.
     *
     * @param array<string, string[]> $phonesByContactId
     * @return string[]
     */
    private function getAllPhonesForContact(Entity $contact, array &$phonesByContactId): array
    {
        $cacheKey = 'all:' . $contact->getId();

        if (isset($phonesByContactId[$cacheKey])) {
            return $phonesByContactId[$cacheKey];
        }

        $phones = [];
        $seen = [];

        /** @var PhoneNumberRepository $repo */
        $repo = $this->entityManager->getRepository(PhoneNumber::ENTITY_TYPE);

        foreach ($repo->getPhoneNumberData($contact) as $row) {
            $phone = $this->normalizePhone($row->phoneNumber ?? null);

            if ($phone === null || isset($seen[$phone])) {
                continue;
            }

            $seen[$phone] = true;
            $phones[] = $phone;
        }

        $primary = $this->normalizePhone($contact->get('phoneNumber'));

        if ($primary !== null && !isset($seen[$primary])) {
            $phones[] = $primary;
        }

        return $phonesByContactId[$cacheKey] = $phones;
    }

    /**
     * Remove contacts that were successfully reached in the given campaigns.
     *
     * @param string[] $excludeCampaignIds WhatsAppCampaign IDs
     * @param array<int, array{contactId: string, phoneNumber: string, contactName: string, targetListIds: string[]}> $audience
     * @return array<int, array{contactId: string, phoneNumber: string, contactName: string, targetListIds: string[]}>
     */
    private function applyExcludeCampaigns(array $excludeCampaignIds, array $audience): array
    {
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
     * Remove contacts that appear in the given excluding target lists.
     *
     * @param iterable<\Espo\ORM\Entity> $excludingTargetLists TargetList entities
     * @param array<int, array{contactId: string, phoneNumber: string, contactName: string, targetListIds: string[]}> $audience
     * @return array<int, array{contactId: string, phoneNumber: string, contactName: string, targetListIds: string[]}>
     */
    private function applyExcludingTargetLists(iterable $excludingTargetLists, array $audience): array
    {
        $excludedPhones = [];
        $phonesByContactId = [];

        foreach ($excludingTargetLists as $targetList) {
            $contacts = $this->entityManager
                ->getRDBRepository('TargetList')
                ->getRelation($targetList, 'contacts')
                ->find();

            foreach ($contacts as $contact) {
                foreach ($this->getAllPhonesForContact($contact, $phonesByContactId) as $phone) {
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

        $this->opportunityService->assertConfiguration($campaign);

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
     * Idempotent: dedupes by phoneNumber against existing
     * WhatsAppCampaignContact rows; the unique DB index
     * (whatsAppCampaignId, phoneNumber) guards against races.
     * Multiple numbers on the same Contact enroll as separate recipients.
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

        return $this->enrollAudience($campaignId, $audience);
    }

    /**
     * Enroll an explicit audience into a running campaign.
     *
     * Diffs the given audience against already-enrolled rows by phoneNumber,
     * creates junction rows for the remainder, updates totalRecipients, and
     * schedules chunk jobs. Used by continuous enrollment and by campaign
     * distributions (allocation layer).
     *
     * Idempotent: the unique DB index (whatsAppCampaignId, phoneNumber)
     * guards against races. Multiple numbers on the same Contact enroll
     * as separate recipients.
     *
     * @param string $campaignId Campaign entity ID
     * @param array<int, array{contactId: string, phoneNumber: string, contactName: string, targetListIds: string[]}> $audience
     * @return int Number of newly enrolled contacts
     * @throws NotFound
     */
    public function enrollAudience(string $campaignId, array $audience): int
    {
        $campaign = $this->entityManager->getEntityById('WhatsAppCampaign', $campaignId);

        if (!$campaign) {
            throw new NotFound("Campaign {$campaignId} not found.");
        }

        if ($campaign->get('status') !== 'Sending') {
            $this->log->warning(
                "WhatsAppCampaignService: Skipped enrollment into campaign {$campaignId} " .
                "(status: {$campaign->get('status')}, expected Sending)."
            );

            return 0;
        }

        if (empty($audience)) {
            return 0;
        }

        $existingRows = $this->entityManager
            ->getRDBRepository('WhatsAppCampaignContact')
            ->where(['whatsAppCampaignId' => $campaignId])
            ->select(['id', 'contactId', 'phoneNumber', 'status'])
            ->find();

        $enrolledPhones = [];
        $unsentRowByPhone = [];

        foreach ($existingRows as $row) {
            $isUnsent = in_array($row->get('status'), ['Pending', 'Retry'], true);
            $phone = $row->get('phoneNumber');

            if (!$phone) {
                continue;
            }

            $enrolledPhones[$phone] = true;

            if ($isUnsent) {
                $unsentRowByPhone[$phone] = $row;
            }
        }

        $newAudience = [];

        foreach ($audience as $item) {
            if (!isset($enrolledPhones[$item['phoneNumber']])) {
                $newAudience[] = $item;
                $enrolledPhones[$item['phoneNumber']] = true;

                continue;
            }

            // Already enrolled: merge newly contributing Target Lists into
            // rows that have not been sent yet. Send-time provenance stays
            // immutable for rows that already left Pending/Retry.
            $row = $unsentRowByPhone[$item['phoneNumber']] ?? null;

            if ($row && !empty($item['targetListIds'])) {
                $this->mergeTargetListsIntoRecipient($row, $item['targetListIds']);
            }
        }

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
     * Merge newly contributing Target Lists into an existing unsent
     * recipient row, so its send-time provenance reflects every list that
     * actually contributed the contact by the time the message goes out.
     *
     * Best-effort: a relation failure must never abort enrollment.
     *
     * @param string[] $targetListIds
     */
    private function mergeTargetListsIntoRecipient(\Espo\ORM\Entity $row, array $targetListIds): void
    {
        try {
            $relation = $this->entityManager
                ->getRDBRepository('WhatsAppCampaignContact')
                ->getRelation($row, 'targetLists');

            foreach ($targetListIds as $targetListId) {
                if (!$relation->isRelatedById($targetListId)) {
                    $relation->relateById($targetListId);
                }
            }
        } catch (\Throwable $e) {
            $this->log->warning(
                "WhatsAppCampaignService: Could not merge Target List provenance into " .
                "recipient {$row->getId()}: {$e->getMessage()}"
            );
        }
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
                'status' => ['Pending', 'Retry', 'Processing'],
            ])
            ->count();

        if ($pendingOrRetryCount === 0 && !$this->isCampaignInActiveDistribution($campaignId)) {
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
     * Validate that a campaign can be activated as a distribution executor,
     * without mutating any state.
     *
     * Used to preflight ALL entry campaigns of a distribution before any of
     * them is transitioned, so a validation failure on a later entry cannot
     * leave earlier entries in Sending while the distribution stays Draft.
     *
     * @param string $campaignId Campaign entity ID
     * @return \Espo\ORM\Entity The validated campaign
     * @throws Error
     * @throws NotFound
     */
    public function assertCanActivateForDistribution(string $campaignId): \Espo\ORM\Entity
    {
        $campaign = $this->entityManager->getEntityById('WhatsAppCampaign', $campaignId);

        if (!$campaign) {
            throw new NotFound("Campaign {$campaignId} not found.");
        }

        $status = $campaign->get('status');

        if ($status === 'Sending') {
            return $campaign;
        }

        if ($status !== 'Draft') {
            throw new Error(
                "Campaign {$campaignId} cannot be used in a distribution (status: {$status}; expected Draft or Sending)."
            );
        }

        if (!$campaign->get('chatwootInboxId') && !$campaign->get('chatwootAccountId')) {
            throw new Error("Campaign {$campaignId} must have a WhatsApp Inbox selected (Meta Cloud API).");
        }

        $chatwootAccountId = $campaign->get('chatwootAccountId');
        if (!$chatwootAccountId) {
            throw new Error("Campaign {$campaignId} must have a Chatwoot Account linked (select a WhatsApp Inbox).");
        }

        $this->opportunityService->assertConfiguration($campaign);

        $chatwootAccount = $this->entityManager->getEntityById('ChatwootAccount', $chatwootAccountId);
        if (!$chatwootAccount) {
            throw new Error("Linked Chatwoot Account not found for campaign {$campaignId}.");
        }

        return $campaign;
    }

    /**
     * Activate a campaign as an executor for a campaign distribution.
     *
     * Unlike launch(), no audience is resolved: the distribution (allocation
     * layer) enrolls recipients. Validates the send infrastructure, syncs
     * templates, and transitions the campaign to Sending. Idempotent for
     * campaigns already in Sending status.
     *
     * @param string $campaignId Campaign entity ID
     * @throws Error
     * @throws NotFound
     */
    public function activateForDistribution(string $campaignId): void
    {
        $campaign = $this->assertCanActivateForDistribution($campaignId);

        if ($campaign->get('status') === 'Sending') {
            return;
        }

        $chatwootAccountId = $campaign->get('chatwootAccountId');
        $chatwootAccount = $this->entityManager->getEntityById('ChatwootAccount', $chatwootAccountId);
        if (!$chatwootAccount) {
            throw new Error("Linked Chatwoot Account not found for campaign {$campaignId}.");
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

        $campaign->set([
            'status' => 'Sending',
            'startedAt' => date('Y-m-d H:i:s'),
        ]);
        $this->entityManager->saveEntity($campaign);

        $this->log->info("WhatsAppCampaignService: Activated campaign {$campaignId} for distribution.");
    }

    /**
     * Check whether a campaign is managed by an Active continuous
     * campaign distribution.
     *
     * Such campaigns must not be auto-completed: the distribution keeps
     * enrolling new recipients until it is stopped.
     *
     * @param string $campaignId Campaign entity ID
     * @param string|null $ignoreDistributionId Distribution ID to ignore (e.g. the one being stopped)
     */
    public function isCampaignInActiveDistribution(string $campaignId, ?string $ignoreDistributionId = null): bool
    {
        $entries = $this->entityManager
            ->getRDBRepository('WhatsAppCampaignDistributionEntry')
            ->where(['campaignId' => $campaignId])
            ->find();

        foreach ($entries as $entry) {
            $distributionId = $entry->get('distributionId');

            if (!$distributionId || $distributionId === $ignoreDistributionId) {
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
     * Create Pending WhatsAppCampaignContact rows for an audience.
     *
     * Duplicate enrollments (unique index on whatsAppCampaignId + phoneNumber)
     * are logged and skipped, making this safe under concurrent sweeps.
     *
     * @param \Espo\ORM\Entity $campaign
     * @param array<int, array{contactId: string, phoneNumber: string, contactName: string, targetListIds: string[]}> $audience
     * @return string[] Created WhatsAppCampaignContact IDs
     */
    private function createCampaignContacts(\Espo\ORM\Entity $campaign, array $audience): array
    {
        $createdIds = [];

        foreach ($audience as $item) {
            $transactionManager = $this->entityManager->getTransactionManager();
            $transactionManager->start();

            try {
                $entity = $this->entityManager->createEntity('WhatsAppCampaignContact', [
                    'whatsAppCampaignId' => $campaign->getId(),
                    'contactId' => $item['contactId'],
                    'chatwootAccountId' => $campaign->get('chatwootAccountId'),
                    'phoneNumber' => $item['phoneNumber'],
                    'contactName' => $item['contactName'],
                    'status' => 'Pending',
                    'opportunityAttributionStatus' => $campaign->get('createOpportunity')
                        ? 'Pending'
                        : 'NotRequested',
                ]);

                foreach ($item['targetListIds'] ?? [] as $targetListId) {
                    $this->entityManager
                        ->getRDBRepository('WhatsAppCampaignContact')
                        ->getRelation($entity, 'targetLists')
                        ->relateById($targetListId);
                }

                $transactionManager->commit();

                $createdIds[] = $entity->getId();
            } catch (\Throwable $e) {
                $transactionManager->rollback();
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

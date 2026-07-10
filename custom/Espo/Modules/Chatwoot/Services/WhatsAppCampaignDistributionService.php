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
use Espo\Core\Utils\Log;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Service for WhatsApp Campaign Distribution lifecycle (allocation layer).
 *
 * A distribution owns an audience (target lists + exclusions) and splits it
 * across one or more WhatsAppCampaign executors by deterministic weighted
 * buckets. Each contact's phone is hashed into a bucket 0-99; entries claim
 * cumulative bucket ranges in creation order (e.g. 50/50 -> A: [0,50),
 * B: [50,100)). The same phone always maps to the same bucket, so
 * re-enrollment sweeps are stable, and a contact is only ever enrolled into
 * one campaign of the distribution.
 */
class WhatsAppCampaignDistributionService
{
    private const BUCKET_COUNT = 100;

    public function __construct(
        private EntityManager $entityManager,
        private WhatsAppCampaignService $campaignService,
        private Log $log
    ) {}

    /**
     * Activate a distribution.
     *
     * Validates entries (weights sum to 100, distinct campaigns in Draft or
     * Sending), activates Draft campaigns as distribution executors, marks
     * the distribution Active, and runs an immediate enrollment pass.
     *
     * @param string $distributionId Distribution entity ID
     * @return Entity Updated distribution entity
     * @throws Error
     * @throws Forbidden
     * @throws NotFound
     */
    public function activate(string $distributionId): Entity
    {
        $distribution = $this->getDistribution($distributionId);

        $status = $distribution->get('status');

        if (!in_array($status, ['Draft', 'Stopped'])) {
            throw new Forbidden(
                "Distribution can only be activated from Draft or Stopped status (current: {$status})."
            );
        }

        $entries = $this->getEntries($distributionId);

        $this->validateEntries($entries);

        if (!$distribution->get('continuous')) {
            $audience = $this->resolveDistributionAudience($distribution);

            if (empty($audience)) {
                throw new Error(
                    'Distribution has no valid recipients. Check Target Lists, or enable continuous enrollment.'
                );
            }
        }

        foreach ($entries as $entry) {
            $this->campaignService->activateForDistribution($entry->get('campaignId'));
        }

        $distribution->set([
            'status' => 'Active',
            'startedAt' => $distribution->get('startedAt') ?: date('Y-m-d H:i:s'),
            'stoppedAt' => null,
        ]);
        $this->entityManager->saveEntity($distribution);

        $this->log->info("WhatsAppCampaignDistributionService: Activated distribution {$distributionId}.");

        try {
            $this->enrollContacts($distributionId);
        } catch (\Throwable $e) {
            // The per-minute sweep will retry; activation itself succeeded.
            $this->log->error(
                "WhatsAppCampaignDistributionService: Initial enrollment pass failed for " .
                "distribution {$distributionId}: {$e->getMessage()}"
            );
        }

        return $this->getDistribution($distributionId);
    }

    /**
     * Stop an Active distribution.
     *
     * Entry campaigns with no remaining Pending/Retry recipients (and not
     * managed by another active distribution or their own continuous
     * enrollment) are completed immediately; others complete via their
     * remaining chunk jobs.
     *
     * @param string $distributionId Distribution entity ID
     * @return Entity Updated distribution entity
     * @throws Forbidden
     * @throws NotFound
     */
    public function stop(string $distributionId): Entity
    {
        $distribution = $this->getDistribution($distributionId);

        if ($distribution->get('status') !== 'Active') {
            throw new Forbidden(
                "Distribution can only be stopped from Active status (current: {$distribution->get('status')})."
            );
        }

        $distribution->set([
            'status' => 'Stopped',
            'stoppedAt' => date('Y-m-d H:i:s'),
        ]);
        $this->entityManager->saveEntity($distribution);

        foreach ($this->getEntries($distributionId) as $entry) {
            $this->completeCampaignIfIdle($entry->get('campaignId'), $distributionId);
        }

        $this->log->info("WhatsAppCampaignDistributionService: Stopped distribution {$distributionId}.");

        return $distribution;
    }

    /**
     * Enroll not-yet-enrolled audience contacts into entry campaigns by
     * deterministic weighted bucket. Used by the sweep job and after
     * activation.
     *
     * Idempotent: contacts already enrolled in ANY campaign of the
     * distribution are skipped (by contactId and phoneNumber), and the
     * per-campaign unique index guards against races.
     *
     * @param string $distributionId Distribution entity ID
     * @return int Number of newly enrolled contacts
     * @throws NotFound
     */
    public function enrollContacts(string $distributionId): int
    {
        $distribution = $this->getDistribution($distributionId);

        if ($distribution->get('status') !== 'Active') {
            return 0;
        }

        $entries = $this->getEntries($distributionId);

        if (empty($entries)) {
            return 0;
        }

        $ranges = $this->buildBucketRanges($entries);

        $campaignIds = array_column($ranges, 'campaignId');

        $audience = $this->resolveDistributionAudience($distribution);

        if (empty($audience)) {
            return 0;
        }

        // Skip contacts already enrolled in ANY campaign of this distribution,
        // so weight changes never re-enroll a contact into a sibling campaign.
        [$enrolledContactIds, $enrolledPhones] = $this->getEnrolledSets($campaignIds);

        $byCampaign = [];

        foreach ($audience as $item) {
            if (
                isset($enrolledContactIds[$item['contactId']]) ||
                isset($enrolledPhones[$item['phoneNumber']])
            ) {
                continue;
            }

            $bucket = self::bucketForPhone($item['phoneNumber']);

            $campaignId = $this->campaignIdForBucket($ranges, $bucket);

            if ($campaignId === null) {
                // Bucket not covered (weights do not sum to 100); leave unassigned.
                continue;
            }

            $byCampaign[$campaignId][] = $item;
        }

        $totalEnrolled = 0;

        foreach ($byCampaign as $campaignId => $subset) {
            try {
                $totalEnrolled += $this->campaignService->enrollAudience($campaignId, $subset);
            } catch (\Throwable $e) {
                $this->log->error(
                    "WhatsAppCampaignDistributionService: Failed to enroll " . count($subset) .
                    " contacts into campaign {$campaignId} (distribution {$distributionId}): {$e->getMessage()}"
                );
            }
        }

        $this->updateTotalEnrolled($distribution, $campaignIds);

        if ($totalEnrolled > 0) {
            $this->log->info(
                "WhatsAppCampaignDistributionService: Enrolled {$totalEnrolled} contacts via " .
                "distribution {$distributionId}."
            );
        }

        return $totalEnrolled;
    }

    /**
     * Auto-stop a non-continuous Active distribution once all its entry
     * campaigns have left Sending status (completed, cancelled, etc.).
     *
     * @param string $distributionId Distribution entity ID
     * @return bool Whether the distribution was stopped
     * @throws NotFound
     */
    public function autoStopIfFinished(string $distributionId): bool
    {
        $distribution = $this->getDistribution($distributionId);

        if ($distribution->get('status') !== 'Active' || $distribution->get('continuous')) {
            return false;
        }

        foreach ($this->getEntries($distributionId) as $entry) {
            $campaign = $this->entityManager->getEntityById('WhatsAppCampaign', $entry->get('campaignId'));

            if ($campaign && $campaign->get('status') === 'Sending') {
                return false;
            }
        }

        $distribution->set([
            'status' => 'Stopped',
            'stoppedAt' => date('Y-m-d H:i:s'),
        ]);
        $this->entityManager->saveEntity($distribution);

        $this->log->info(
            "WhatsAppCampaignDistributionService: Auto-stopped finished distribution {$distributionId}."
        );

        return true;
    }

    /**
     * Create an A/B/n test from a Draft campaign in one step.
     *
     * Clones the campaign as N variant campaigns (same inbox/credentials,
     * different templates), moves the audience (target lists + exclusions)
     * and the continuous-enrollment intent off the campaign onto a new
     * distribution, creates the weighted entries, and optionally activates.
     *
     * @param string $campaignId Source (variant A) campaign ID
     * @param \stdClass $data {
     *     weight: int (bucket share of the original campaign, 1-99),
     *     variants: array<\stdClass> [{
     *         name: string, weight: int (1-99),
     *         templateName: string, templateLanguage?: string,
     *         templateCategory?: string, templateBody?: string,
     *         parameterMapping?: object, headerMediaUrl?: string, headerMediaType?: string
     *     }],
     *     activate?: bool
     * }
     * @return array{distribution: Entity, activationError: ?string}
     * @throws Error
     * @throws Forbidden
     * @throws NotFound
     */
    public function createAbTest(string $campaignId, \stdClass $data): array
    {
        $campaign = $this->entityManager->getEntityById('WhatsAppCampaign', $campaignId);

        if (!$campaign) {
            throw new NotFound("Campaign {$campaignId} not found.");
        }

        if ($campaign->get('status') !== 'Draft') {
            throw new Forbidden(
                "An A/B test can only be created from a Draft campaign (current: {$campaign->get('status')})."
            );
        }

        $existingEntry = $this->entityManager
            ->getRDBRepository('WhatsAppCampaignDistributionEntry')
            ->where(['campaignId' => $campaignId])
            ->findOne();

        if ($existingEntry) {
            throw new Error('Campaign is already part of a distribution.');
        }

        $weight = (int) ($data->weight ?? 0);
        $variantDataList = $data->variants ?? [];

        if (!is_array($variantDataList) || empty($variantDataList)) {
            throw new Error('At least one variant is required.');
        }

        if ($weight < 1 || $weight > 99) {
            throw new Error('Weight for the original campaign must be between 1 and 99.');
        }

        $weightSum = $weight;

        foreach ($variantDataList as $index => $variantData) {
            $ordinal = $index + 2; // Variant "B" is the 2nd arm.

            $variantName = trim((string) ($variantData->name ?? ''));
            $variantTemplateName = trim((string) ($variantData->templateName ?? ''));
            $variantWeight = (int) ($variantData->weight ?? 0);

            if ($variantName === '') {
                throw new Error("Variant {$ordinal} has no name.");
            }

            if ($variantTemplateName === '') {
                throw new Error("Variant {$ordinal} ('{$variantName}') has no template selected.");
            }

            if ($variantWeight < 1 || $variantWeight > 99) {
                throw new Error("Weight of variant '{$variantName}' must be between 1 and 99.");
            }

            $weightSum += $variantWeight;
        }

        if ($weightSum !== self::BUCKET_COUNT) {
            throw new Error("Weights must sum to 100 (current sum: {$weightSum}).");
        }

        $campaignRepo = $this->entityManager->getRDBRepository('WhatsAppCampaign');

        $manualContact = $campaignRepo->getRelation($campaign, 'manualContacts')->findOne();

        if ($manualContact) {
            throw new Error(
                'A/B tests use Target Lists as the audience. Remove Manual Contacts from the campaign first.'
            );
        }

        $targetLists = iterator_to_array($campaignRepo->getRelation($campaign, 'targetLists')->find());

        if (empty($targetLists)) {
            throw new Error('Campaign has no Target Lists. Add at least one Target List before creating an A/B test.');
        }

        $excludeCampaigns = iterator_to_array($campaignRepo->getRelation($campaign, 'excludeCampaigns')->find());
        $excludingTargetLists = iterator_to_array($campaignRepo->getRelation($campaign, 'excludingTargetLists')->find());
        $teams = iterator_to_array($campaignRepo->getRelation($campaign, 'teams')->find());

        $continuous = (bool) $campaign->get('continuousEnrollment');

        // Variant campaigns: same infrastructure, different template, no own audience.
        $variants = [];

        foreach ($variantDataList as $variantData) {
            $variant = $this->entityManager->getNewEntity('WhatsAppCampaign');

            $variant->set([
                'name' => trim((string) $variantData->name),
                'status' => 'Draft',
                'chatwootInboxId' => $campaign->get('chatwootInboxId'),
                'credentialId' => $campaign->get('credentialId'),
                'chatwootAccountId' => $campaign->get('chatwootAccountId'),
                'tenantId' => $campaign->get('tenantId'),
                'wabaId' => $campaign->get('wabaId'),
                'templateName' => trim((string) $variantData->templateName),
                'templateLanguage' => $variantData->templateLanguage ?? $campaign->get('templateLanguage'),
                'templateCategory' => $variantData->templateCategory ?? null,
                'templateBody' => $variantData->templateBody ?? null,
                'parameterMapping' => $variantData->parameterMapping ?? null,
                'headerMediaUrl' => $variantData->headerMediaUrl ?? null,
                'headerMediaType' => $variantData->headerMediaType ?? null,
                'continuousEnrollment' => false,
            ]);
            $this->entityManager->saveEntity($variant);

            $variants[] = [$variant, (int) $variantData->weight];
        }

        $distribution = $this->entityManager->getNewEntity('WhatsAppCampaignDistribution');

        $distribution->set([
            'name' => $campaign->get('name') . ' — A/B',
            'status' => 'Draft',
            'continuous' => $continuous,
        ]);
        $this->entityManager->saveEntity($distribution);

        $distributionRepo = $this->entityManager->getRDBRepository('WhatsAppCampaignDistribution');

        // Move the audience definition from the campaign to the distribution.
        foreach ($targetLists as $targetList) {
            $distributionRepo->getRelation($distribution, 'targetLists')->relate($targetList);
            $campaignRepo->getRelation($campaign, 'targetLists')->unrelate($targetList);
        }

        foreach ($excludeCampaigns as $excludeCampaign) {
            $distributionRepo->getRelation($distribution, 'excludeCampaigns')->relate($excludeCampaign);
            $campaignRepo->getRelation($campaign, 'excludeCampaigns')->unrelate($excludeCampaign);
        }

        foreach ($excludingTargetLists as $excludingTargetList) {
            $distributionRepo->getRelation($distribution, 'excludingTargetLists')->relate($excludingTargetList);
            $campaignRepo->getRelation($campaign, 'excludingTargetLists')->unrelate($excludingTargetList);
        }

        foreach ($teams as $team) {
            $distributionRepo->getRelation($distribution, 'teams')->relate($team);

            foreach ($variants as [$variant]) {
                $campaignRepo->getRelation($variant, 'teams')->relate($team);
            }
        }

        if ($continuous) {
            // Enrollment is now driven by the distribution sweep.
            $campaign->set('continuousEnrollment', false);
            $this->entityManager->saveEntity($campaign);
        }

        $entrySpecs = [[$campaignId, $weight]];

        foreach ($variants as [$variant, $variantWeight]) {
            $entrySpecs[] = [$variant->getId(), $variantWeight];
        }

        foreach ($entrySpecs as [$entryCampaignId, $entryWeight]) {
            $entry = $this->entityManager->getNewEntity('WhatsAppCampaignDistributionEntry');

            $entry->set([
                'distributionId' => $distribution->getId(),
                'campaignId' => $entryCampaignId,
                'weight' => $entryWeight,
            ]);
            $this->entityManager->saveEntity($entry);
        }

        $this->log->info(
            "WhatsAppCampaignDistributionService: Created A/B test distribution {$distribution->getId()} " .
            "from campaign {$campaignId} with " . count($variants) . " variant(s), weights " .
            implode('/', array_column($entrySpecs, 1)) . "."
        );

        $activationError = null;

        if (!empty($data->activate)) {
            try {
                $distribution = $this->activate($distribution->getId());
            } catch (\Throwable $e) {
                // Keep the created records in Draft; the user can fix and activate manually.
                $activationError = $e->getMessage();

                $this->log->error(
                    "WhatsAppCampaignDistributionService: A/B test distribution {$distribution->getId()} " .
                    "created but activation failed: {$activationError}"
                );
            }
        }

        return [
            'distribution' => $distribution,
            'activationError' => $activationError,
        ];
    }

    /**
     * Deterministic bucket (0-99) for a normalized phone number.
     *
     * crc32 is stable across PHP runs and platforms; the double-modulo
     * guards against negative values on 32-bit builds.
     */
    public static function bucketForPhone(string $phoneNumber): int
    {
        return ((crc32($phoneNumber) % self::BUCKET_COUNT) + self::BUCKET_COUNT) % self::BUCKET_COUNT;
    }

    /**
     * @throws NotFound
     */
    private function getDistribution(string $distributionId): Entity
    {
        $distribution = $this->entityManager
            ->getEntityById('WhatsAppCampaignDistribution', $distributionId);

        if (!$distribution) {
            throw new NotFound("Distribution {$distributionId} not found.");
        }

        return $distribution;
    }

    /**
     * Entry rows in deterministic (creation) order. Order defines the
     * cumulative bucket ranges, so it must be stable between sweeps.
     *
     * @return Entity[]
     */
    private function getEntries(string $distributionId): array
    {
        $collection = $this->entityManager
            ->getRDBRepository('WhatsAppCampaignDistributionEntry')
            ->where(['distributionId' => $distributionId])
            ->order([['createdAt', 'ASC'], ['id', 'ASC']])
            ->find();

        $entries = [];

        foreach ($collection as $entry) {
            $entries[] = $entry;
        }

        return $entries;
    }

    /**
     * Validate distribution entries for activation.
     *
     * @param Entity[] $entries
     * @throws Error
     */
    private function validateEntries(array $entries): void
    {
        if (empty($entries)) {
            throw new Error('Distribution has no campaign entries. Add at least one campaign with a weight.');
        }

        $weightSum = 0;
        $seenCampaignIds = [];

        foreach ($entries as $entry) {
            $campaignId = $entry->get('campaignId');
            $weight = (int) $entry->get('weight');

            if (!$campaignId) {
                throw new Error('Distribution entry has no campaign linked.');
            }

            if ($weight < 1 || $weight > 100) {
                throw new Error("Entry weight must be between 1 and 100 (got {$weight}).");
            }

            if (isset($seenCampaignIds[$campaignId])) {
                throw new Error('The same campaign is linked by more than one distribution entry.');
            }

            $seenCampaignIds[$campaignId] = true;
            $weightSum += $weight;

            $campaign = $this->entityManager->getEntityById('WhatsAppCampaign', $campaignId);

            if (!$campaign) {
                throw new Error("Campaign {$campaignId} linked by a distribution entry was not found.");
            }

            $campaignStatus = $campaign->get('status');

            if (!in_array($campaignStatus, ['Draft', 'Sending'])) {
                throw new Error(
                    "Campaign '{$campaign->get('name')}' has status {$campaignStatus}; " .
                    "only Draft or Sending campaigns can be used in a distribution."
                );
            }
        }

        if ($weightSum !== self::BUCKET_COUNT) {
            throw new Error("Entry weights must sum to 100 (current sum: {$weightSum}).");
        }
    }

    /**
     * Cumulative bucket ranges per entry, in entry order.
     *
     * @param Entity[] $entries
     * @return array<int, array{campaignId: string, from: int, to: int}>
     */
    private function buildBucketRanges(array $entries): array
    {
        $ranges = [];
        $cursor = 0;

        foreach ($entries as $entry) {
            $weight = (int) $entry->get('weight');

            $ranges[] = [
                'campaignId' => $entry->get('campaignId'),
                'from' => $cursor,
                'to' => $cursor + $weight,
            ];

            $cursor += $weight;
        }

        if ($cursor !== self::BUCKET_COUNT) {
            $this->log->warning(
                "WhatsAppCampaignDistributionService: Entry weights sum to {$cursor} (expected 100); " .
                "some buckets are unassigned or unreachable."
            );
        }

        return $ranges;
    }

    /**
     * @param array<int, array{campaignId: string, from: int, to: int}> $ranges
     */
    private function campaignIdForBucket(array $ranges, int $bucket): ?string
    {
        foreach ($ranges as $range) {
            if ($bucket >= $range['from'] && $bucket < $range['to']) {
                return $range['campaignId'];
            }
        }

        return null;
    }

    /**
     * Resolve the distribution's audience from its target lists and exclusions.
     *
     * @return array<int, array{contactId: string, phoneNumber: string, contactName: string}>
     */
    private function resolveDistributionAudience(Entity $distribution): array
    {
        $repository = $this->entityManager->getRDBRepository('WhatsAppCampaignDistribution');

        $targetLists = $repository->getRelation($distribution, 'targetLists')->find();

        $excludeCampaigns = $repository->getRelation($distribution, 'excludeCampaigns')->find();

        $excludeCampaignIds = [];
        foreach ($excludeCampaigns as $ec) {
            $excludeCampaignIds[] = $ec->getId();
        }

        $excludingTargetLists = $repository->getRelation($distribution, 'excludingTargetLists')->find();

        $audience = $this->campaignService->resolveAudienceFromSources(
            $targetLists,
            [],
            $excludeCampaignIds,
            $excludingTargetLists
        );

        $this->log->info(
            "WhatsAppCampaignDistributionService: Resolved audience of " . count($audience) .
            " contacts for distribution {$distribution->getId()}."
        );

        return $audience;
    }

    /**
     * Contact IDs and phone numbers already enrolled in any of the given campaigns.
     *
     * @param string[] $campaignIds
     * @return array{0: array<string, bool>, 1: array<string, bool>}
     */
    private function getEnrolledSets(array $campaignIds): array
    {
        $enrolledContactIds = [];
        $enrolledPhones = [];

        if (empty($campaignIds)) {
            return [$enrolledContactIds, $enrolledPhones];
        }

        $rows = $this->entityManager
            ->getRDBRepository('WhatsAppCampaignContact')
            ->where(['whatsAppCampaignId' => $campaignIds])
            ->select(['id', 'contactId', 'phoneNumber'])
            ->find();

        foreach ($rows as $row) {
            if ($row->get('contactId')) {
                $enrolledContactIds[$row->get('contactId')] = true;
            }
            if ($row->get('phoneNumber')) {
                $enrolledPhones[$row->get('phoneNumber')] = true;
            }
        }

        return [$enrolledContactIds, $enrolledPhones];
    }

    /**
     * @param string[] $campaignIds
     */
    private function updateTotalEnrolled(Entity $distribution, array $campaignIds): void
    {
        $total = 0;

        if (!empty($campaignIds)) {
            $total = $this->entityManager
                ->getRDBRepository('WhatsAppCampaignContact')
                ->where(['whatsAppCampaignId' => $campaignIds])
                ->count();
        }

        if ((int) $distribution->get('totalEnrolled') === $total) {
            return;
        }

        $distribution->set('totalEnrolled', $total);
        $this->entityManager->saveEntity($distribution);
    }

    /**
     * Complete a Sending entry campaign that has nothing left to process
     * and is not managed by anything else.
     */
    private function completeCampaignIfIdle(?string $campaignId, string $stoppedDistributionId): void
    {
        if (!$campaignId) {
            return;
        }

        $campaign = $this->entityManager->getEntityById('WhatsAppCampaign', $campaignId);

        if (!$campaign || $campaign->get('status') !== 'Sending') {
            return;
        }

        if ($campaign->get('continuousEnrollment')) {
            return;
        }

        if ($this->campaignService->isCampaignInActiveDistribution($campaignId, $stoppedDistributionId)) {
            return;
        }

        $pendingOrRetryCount = $this->entityManager
            ->getRDBRepository('WhatsAppCampaignContact')
            ->where([
                'whatsAppCampaignId' => $campaignId,
                'status' => ['Pending', 'Retry'],
            ])
            ->count();

        if ($pendingOrRetryCount > 0) {
            // Remaining chunk jobs will complete the campaign once done.
            return;
        }

        $campaign->set([
            'status' => 'Completed',
            'completedAt' => date('Y-m-d H:i:s'),
        ]);
        $this->entityManager->saveEntity($campaign);

        $this->log->info(
            "WhatsAppCampaignDistributionService: Completed idle campaign {$campaignId} " .
            "after distribution stop."
        );
    }
}

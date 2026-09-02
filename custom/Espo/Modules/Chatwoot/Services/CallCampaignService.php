<?php
/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2026 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Services;

use Espo\Core\Exceptions\Error;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Job\JobSchedulerFactory;
use Espo\Core\Utils\Log;
use Espo\Modules\Chatwoot\Tools\PhoneNormalizer;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Call Campaign lifecycle management (power dialer).
 *
 * Launch resolves the audience (Target Lists + manual contacts, minus
 * call-opted-out, ambiguous phones and exclusions), creates the
 * CallCampaignContact enrollments, seeds a Chatwoot dialer campaign, and
 * schedules chunk jobs that push the lead list into Chatwoot. Outcomes flow
 * back via the `dialer_lead_dispositioned` webhook (CallCampaignOutcomeSync).
 */
class CallCampaignService
{
    /** Leads pushed to Chatwoot per seeding job. */
    private const LEAD_CHUNK_SIZE = 200;

    public function __construct(
        private EntityManager $entityManager,
        private ChatwootApiClient $chatwootApiClient,
        private JobSchedulerFactory $jobSchedulerFactory,
        private CallCampaignOpportunityService $opportunityService,
        private Log $log,
    ) {}

    /**
     * Launch a call campaign: resolve audience, enroll contacts, seed the
     * Chatwoot dialer campaign and schedule lead-push chunks.
     *
     * @throws NotFound
     * @throws Forbidden
     * @throws Error
     */
    public function launch(string $campaignId): Entity
    {
        $campaign = $this->getCampaign($campaignId);

        if ($campaign->get('status') !== 'Draft') {
            throw new Forbidden(
                "Call campaign can only be launched from Draft status (current: {$campaign->get('status')})."
            );
        }

        $inbox = $this->resolveCampaignInbox($campaign);

        // Throws when Opportunity creation is enabled but misconfigured.
        $this->opportunityService->assertConfiguration($campaign);

        $audience = $this->resolveAudience($campaign);

        if ($audience === []) {
            throw new Error('Call campaign has no callable recipients. Check Target Lists and phone numbers.');
        }

        $enrollmentIds = $this->createEnrollments($campaign, $audience);
        $context = $this->resolvePlatformContext($campaign, $inbox);

        $response = $this->chatwootApiClient->createDialerCampaign(
            $context['platformUrl'],
            $context['apiKey'],
            $context['chatwootAccountId'],
            [
                'name' => (string) $campaign->get('name'),
                'inbox_id' => $context['chatwootInboxId'],
                'source_ref' => $campaign->getId(),
                'config' => [
                    'max_attempts' => (int) ($campaign->get('maxAttempts') ?: 3),
                    'retry_delay_minutes' => (int) ($campaign->get('retryDelayMinutes') ?: 60),
                ],
            ]
        );

        $dialerCampaignId = $response['id'] ?? null;

        if (!$dialerCampaignId) {
            throw new Error('Chatwoot did not return a dialer campaign id.');
        }

        $campaign->set([
            'status' => 'Active',
            'dialerCampaignId' => (int) $dialerCampaignId,
        ]);
        $this->entityManager->saveEntity($campaign);

        $this->scheduleChunkJobs($campaignId, $enrollmentIds);

        $this->log->info(sprintf(
            'CallCampaignService: Launched campaign %s with %d recipients (Chatwoot dialer campaign %d).',
            $campaignId,
            count($enrollmentIds),
            $dialerCampaignId,
        ));

        return $campaign;
    }

    /**
     * Abort a running campaign. Cancels locally and closes the Chatwoot-side
     * dialer campaign; a Chatwoot API failure is logged but never blocks the
     * local cancel.
     *
     * @throws NotFound
     * @throws Forbidden
     */
    public function abort(string $campaignId): Entity
    {
        $campaign = $this->getCampaign($campaignId);

        if (!in_array($campaign->get('status'), ['Active', 'Paused'], true)) {
            throw new Forbidden(
                "Call campaign can only be aborted from Active or Paused status (current: {$campaign->get('status')})."
            );
        }

        $dialerCampaignId = $campaign->get('dialerCampaignId');

        if ($dialerCampaignId) {
            try {
                $inbox = $this->resolveCampaignInbox($campaign);
                $context = $this->resolvePlatformContext($campaign, $inbox);

                $this->chatwootApiClient->updateDialerCampaign(
                    $context['platformUrl'],
                    $context['apiKey'],
                    $context['chatwootAccountId'],
                    (int) $dialerCampaignId,
                    ['status' => 'completed']
                );
            } catch (\Throwable $e) {
                $this->log->warning(sprintf(
                    'CallCampaignService: Could not close Chatwoot dialer campaign %s: %s',
                    (string) $dialerCampaignId,
                    $e->getMessage(),
                ));
            }
        }

        $campaign->set('status', 'Cancelled');
        $this->entityManager->saveEntity($campaign);

        return $campaign;
    }

    /**
     * Resolve the audience: contacts from Target Lists plus manual contacts,
     * filtered by per-TargetList opt-out and global callOptedOut, normalized
     * to E.164, de-duplicated (ambiguous shared phones are dropped), with
     * campaign and Target List exclusions applied.
     *
     * @return array<int, array{contactId: string, phoneNumber: string, contactName: string, targetListIds: string[]}>
     */
    public function resolveAudience(Entity $campaign): array
    {
        $repository = $this->entityManager->getRDBRepository('CallCampaign');

        $audience = [];
        $phoneOwners = [];

        foreach ($repository->getRelation($campaign, 'targetLists')->find() as $targetList) {
            $contacts = $this->entityManager
                ->getRDBRepository('TargetList')
                ->getRelation($targetList, 'contacts')
                ->where(['@relation.optedOut' => false])
                ->find();

            foreach ($contacts as $contact) {
                $this->accumulateContact($contact, (string) $targetList->getId(), $audience, $phoneOwners);
            }
        }

        foreach ($repository->getRelation($campaign, 'manualContacts')->find() as $contact) {
            $this->accumulateContact($contact, null, $audience, $phoneOwners);
        }

        // Drop phones shared by more than one contact: the dialer cannot
        // know which Contact the outcome belongs to.
        $audience = array_values(array_filter(
            $audience,
            static fn (array $item): bool => ($phoneOwners[$item['phoneNumber']] ?? 0) === 1
        ));

        $excludedPhones = $this->excludedPhones($campaign);
        $audience = array_values(array_filter(
            $audience,
            static fn (array $item): bool => !isset($excludedPhones[$item['phoneNumber']])
        ));

        $this->log->info(sprintf(
            'CallCampaignService: Resolved audience of %d contacts for campaign %s.',
            count($audience),
            $campaign->getId(),
        ));

        return $audience;
    }

    /**
     * @param array<string, array{contactId: string, phoneNumber: string, contactName: string, targetListIds: string[]}> $audience
     * @param array<string, int> $phoneOwners
     */
    private function accumulateContact(
        Entity $contact,
        ?string $targetListId,
        array &$audience,
        array &$phoneOwners
    ): void {
        if ($contact->get('callOptedOut')) {
            return;
        }

        $phone = PhoneNormalizer::normalize($contact->get('phoneNumber'));

        if (!$phone) {
            return;
        }

        $phoneOwners[$phone] = ($phoneOwners[$phone] ?? 0) + 1;

        if (isset($audience[$phone])) {
            // Same contact reached through another list: merge provenance.
            if ($audience[$phone]['contactId'] === $contact->getId() && $targetListId) {
                $audience[$phone]['targetListIds'][] = $targetListId;
            }

            return;
        }

        $audience[$phone] = [
            'contactId' => $contact->getId(),
            'phoneNumber' => $phone,
            'contactName' => (string) ($contact->get('name') ?: $phone),
            'targetListIds' => $targetListId ? [$targetListId] : [],
        ];
    }

    /**
     * Phones excluded via excluded campaigns' enrollments and excluding
     * Target Lists' members.
     *
     * @return array<string, true>
     */
    private function excludedPhones(Entity $campaign): array
    {
        $phones = [];

        $excludeCampaignIds = [];

        foreach (
            $this->entityManager->getRDBRepository('CallCampaign')
                ->getRelation($campaign, 'excludeCampaigns')
                ->find() as $excludeCampaign
        ) {
            $excludeCampaignIds[] = $excludeCampaign->getId();
        }

        if ($excludeCampaignIds !== []) {
            $enrollments = $this->entityManager
                ->getRDBRepository('CallCampaignContact')
                ->where(['callCampaignId' => $excludeCampaignIds])
                ->find();

            foreach ($enrollments as $enrollment) {
                $phones[$enrollment->get('phoneNumber')] = true;
            }
        }

        foreach (
            $this->entityManager->getRDBRepository('CallCampaign')
                ->getRelation($campaign, 'excludingTargetLists')
                ->find() as $targetList
        ) {
            $contacts = $this->entityManager
                ->getRDBRepository('TargetList')
                ->getRelation($targetList, 'contacts')
                ->find();

            foreach ($contacts as $contact) {
                $phone = PhoneNormalizer::normalize($contact->get('phoneNumber'));

                if ($phone) {
                    $phones[$phone] = true;
                }
            }
        }

        return $phones;
    }

    /**
     * @param array<int, array{contactId: string, phoneNumber: string, contactName: string, targetListIds: string[]}> $audience
     * @return string[]
     */
    private function createEnrollments(Entity $campaign, array $audience): array
    {
        $createdIds = [];

        foreach ($audience as $item) {
            try {
                $entity = $this->entityManager->createEntity('CallCampaignContact', [
                    'callCampaignId' => $campaign->getId(),
                    'contactId' => $item['contactId'],
                    'chatwootAccountId' => $campaign->get('chatwootAccountId'),
                    'phoneNumber' => $item['phoneNumber'],
                    'contactName' => $item['contactName'],
                    'status' => 'Pending',
                    'opportunityAttributionStatus' => $campaign->get('createOpportunity')
                        ? 'Pending'
                        : 'NotRequested',
                ]);

                foreach ($item['targetListIds'] as $targetListId) {
                    $this->entityManager
                        ->getRDBRepository('CallCampaignContact')
                        ->getRelation($entity, 'targetLists')
                        ->relateById($targetListId);
                }

                $createdIds[] = $entity->getId();
            } catch (\Throwable $e) {
                $this->log->warning(sprintf(
                    'CallCampaignService: Skipped enrolling contact %s into campaign %s: %s',
                    $item['contactId'],
                    $campaign->getId(),
                    $e->getMessage(),
                ));
            }
        }

        return $createdIds;
    }

    /**
     * @param string[] $enrollmentIds
     */
    private function scheduleChunkJobs(string $campaignId, array $enrollmentIds): int
    {
        $chunks = array_chunk($enrollmentIds, self::LEAD_CHUNK_SIZE);

        foreach ($chunks as $chunk) {
            $this->jobSchedulerFactory
                ->create()
                ->setClassName('Espo\\Modules\\Chatwoot\\Jobs\\ProcessCallCampaignChunk')
                ->setData([
                    'campaignId' => $campaignId,
                    'enrollmentIds' => $chunk,
                ])
                ->schedule();
        }

        return count($chunks);
    }

    /**
     * @return array{platformUrl: string, apiKey: string, chatwootAccountId: int, chatwootInboxId: int}
     * @throws Error
     */
    private function resolvePlatformContext(Entity $campaign, Entity $inbox): array
    {
        $chatwootAccount = $this->entityManager->getEntityById('ChatwootAccount', (string) $campaign->get('chatwootAccountId'));

        if (!$chatwootAccount) {
            throw new Error("Chat account not found for call campaign {$campaign->getId()}.");
        }

        $platform = $this->entityManager->getEntityById('ChatwootPlatform', (string) $chatwootAccount->get('platformId'));

        if (!$platform) {
            throw new Error('Chatwoot platform not found for the campaign chat account.');
        }

        $platformUrl = $platform->get('backendUrl');
        $apiKey = $chatwootAccount->get('apiKey');
        $chatwootAccountId = (int) $chatwootAccount->get('chatwootAccountId');
        $chatwootInboxId = (int) $inbox->get('chatwootInboxId');

        if (!$platformUrl || !$apiKey || !$chatwootAccountId || !$chatwootInboxId) {
            throw new Error('Missing Chatwoot connection details (URL, API key, account ID or inbox ID).');
        }

        return [
            'platformUrl' => (string) $platformUrl,
            'apiKey' => (string) $apiKey,
            'chatwootAccountId' => $chatwootAccountId,
            'chatwootInboxId' => $chatwootInboxId,
        ];
    }

    /**
     * @throws NotFound
     * @throws Error
     */
    private function resolveCampaignInbox(Entity $campaign): Entity
    {
        $inboxId = $campaign->get('chatwootInboxId');

        $inbox = $inboxId
            ? $this->entityManager->getEntityById('ChatwootInbox', (string) $inboxId)
            : null;

        if (!$inbox || $inbox->get('chatwootAccountId') !== $campaign->get('chatwootAccountId')) {
            throw new Error('Call campaign has no inbox linked to its chat account.');
        }

        return $inbox;
    }

    /**
     * @throws NotFound
     */
    private function getCampaign(string $campaignId): Entity
    {
        $campaign = $this->entityManager->getEntityById('CallCampaign', $campaignId);

        if (!$campaign) {
            throw new NotFound("Call campaign {$campaignId} not found.");
        }

        return $campaign;
    }
}

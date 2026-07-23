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

namespace Espo\Modules\FeatureEmailCampaign\Services;

use Espo\Core\Exceptions\Error;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Job\JobSchedulerFactory;
use Espo\Core\Utils\Log;
use Espo\Entities\EmailAddress;
use Espo\Modules\FeatureEmailCampaign\Tools\EmailDomainMxValidator;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\Repositories\EmailAddress as EmailAddressRepository;

/**
 * Email Campaign lifecycle: audience, launch, continuous enroll, stop, abort.
 */
class EmailCampaignService
{
    private const CHUNK_SIZE = 50;

    public const SKIP_REASON_NO_MX =
        'Domain has no valid MX/A record; skipped to avoid bounce.';

    /**
     * Addresses skipped on the last resolveAudience / resolveAudienceFromSources call.
     *
     * @var array<int, array{contactId: string, emailAddress: string, contactName: string, targetListIds: string[], skipReason: string}>
     */
    private array $lastSkippedAudience = [];

    public function __construct(
        private EntityManager $entityManager,
        private Log $log,
        private JobSchedulerFactory $jobSchedulerFactory,
        private EmailCampaignOpportunityService $opportunityService,
    ) {}

    /**
     * @return array<int, array{contactId: string, emailAddress: string, contactName: string, targetListIds: string[]}>
     */
    public function resolveAudience(string $campaignId): array
    {
        $campaign = $this->entityManager->getEntityById('EmailCampaign', $campaignId);

        if (!$campaign) {
            throw new NotFound("Campaign {$campaignId} not found.");
        }

        $targetLists = $this->entityManager
            ->getRDBRepository('EmailCampaign')
            ->getRelation($campaign, 'targetLists')
            ->find();

        $manualContacts = $this->entityManager
            ->getRDBRepository('EmailCampaign')
            ->getRelation($campaign, 'manualContacts')
            ->find();

        $excludeCampaigns = $this->entityManager
            ->getRDBRepository('EmailCampaign')
            ->getRelation($campaign, 'excludeCampaigns')
            ->find();

        $excludeCampaignIds = [];
        foreach ($excludeCampaigns as $ec) {
            $excludeCampaignIds[] = $ec->getId();
        }

        $excludingTargetLists = $this->entityManager
            ->getRDBRepository('EmailCampaign')
            ->getRelation($campaign, 'excludingTargetLists')
            ->find();

        return $this->resolveAudienceFromSources(
            $targetLists,
            $manualContacts,
            $excludeCampaignIds,
            $excludingTargetLists
        );
    }

    /**
     * @return array<int, array{contactId: string, emailAddress: string, contactName: string, targetListIds: string[], skipReason: string}>
     */
    public function getLastSkippedAudience(): array
    {
        return $this->lastSkippedAudience;
    }

    /**
     * @param iterable<Entity> $targetLists
     * @param iterable<Entity> $manualContacts
     * @param string[] $excludeCampaignIds
     * @param iterable<Entity> $excludingTargetLists
     * @return array<int, array{contactId: string, emailAddress: string, contactName: string, targetListIds: string[]}>
     */
    public function resolveAudienceFromSources(
        iterable $targetLists,
        iterable $manualContacts,
        array $excludeCampaignIds,
        iterable $excludingTargetLists
    ): array {
        $audience = [];
        $skipped = [];
        $indexByEmail = [];
        $skippedIndexByEmail = [];
        $ambiguousEmails = [];
        $emailsByContactId = [];

        foreach ($targetLists as $targetList) {
            $targetListId = (string) $targetList->getId();
            $contacts = $this->entityManager
                ->getRDBRepository('TargetList')
                ->getRelation($targetList, 'contacts')
                ->where(['@relation.optedOut' => false])
                ->find();

            foreach ($contacts as $contact) {
                $this->accumulateContact(
                    $contact,
                    $targetListId,
                    $audience,
                    $skipped,
                    $indexByEmail,
                    $skippedIndexByEmail,
                    $ambiguousEmails,
                    $emailsByContactId
                );
            }
        }

        foreach ($manualContacts as $contact) {
            $this->accumulateContact(
                $contact,
                null,
                $audience,
                $skipped,
                $indexByEmail,
                $skippedIndexByEmail,
                $ambiguousEmails,
                $emailsByContactId
            );
        }

        if ($ambiguousEmails !== []) {
            $audience = array_values(array_filter(
                $audience,
                static fn (array $item): bool => !isset($ambiguousEmails[$item['emailAddress']])
            ));
            $skipped = array_values(array_filter(
                $skipped,
                static fn (array $item): bool => !isset($ambiguousEmails[$item['emailAddress']])
            ));
        }

        $excludeEmails = $this->collectExcludedEmails($excludeCampaignIds, $excludingTargetLists);

        if ($excludeEmails !== []) {
            $audience = array_values(array_filter(
                $audience,
                static fn (array $item): bool => !isset($excludeEmails[$item['emailAddress']])
            ));
            $skipped = array_values(array_filter(
                $skipped,
                static fn (array $item): bool => !isset($excludeEmails[$item['emailAddress']])
            ));
        }

        // Never treat the same address as both sendable and skipped.
        if ($audience !== []) {
            $sendableSet = [];
            foreach ($audience as $item) {
                $sendableSet[$item['emailAddress']] = true;
            }
            $skipped = array_values(array_filter(
                $skipped,
                static fn (array $item): bool => !isset($sendableSet[$item['emailAddress']])
            ));
        }

        $this->lastSkippedAudience = $skipped;

        return $audience;
    }

    /**
     * @param array<int, array{contactId: string, emailAddress: string, contactName: string, targetListIds: string[]}> $audience
     * @param array<int, array{contactId: string, emailAddress: string, contactName: string, targetListIds: string[], skipReason: string}> $skipped
     * @param array<string, int> $indexByEmail
     * @param array<string, int> $skippedIndexByEmail
     * @param array<string, true> $ambiguousEmails
     * @param array<string, array{sendable: string[], skipped: array<int, array{emailAddress: string, reason: string}>}> $emailsByContactId
     */
    private function accumulateContact(
        Entity $contact,
        ?string $targetListId,
        array &$audience,
        array &$skipped,
        array &$indexByEmail,
        array &$skippedIndexByEmail,
        array &$ambiguousEmails,
        array &$emailsByContactId
    ): void {
        $classified = $this->classifyEmailsForContact($contact, $emailsByContactId);
        $contactName = (string) ($contact->get('name') ?? '');
        $contactId = $contact->getId();

        foreach ($classified['sendable'] as $email) {
            $this->addAudienceItem(
                $audience,
                $indexByEmail,
                $ambiguousEmails,
                $contactId,
                $email,
                $contactName,
                $targetListId
            );
        }

        foreach ($classified['skipped'] as $row) {
            $email = $row['emailAddress'];

            if (isset($skippedIndexByEmail[$email])) {
                $index = $skippedIndexByEmail[$email];

                if ($skipped[$index]['contactId'] !== $contactId) {
                    $ambiguousEmails[$email] = true;

                    continue;
                }

                if ($targetListId !== null) {
                    $skipped[$index]['targetListIds'][] = $targetListId;
                }

                continue;
            }

            $skippedIndexByEmail[$email] = count($skipped);
            $skipped[] = [
                'contactId' => $contactId,
                'emailAddress' => $email,
                'contactName' => $contactName,
                'targetListIds' => $targetListId !== null ? [$targetListId] : [],
                'skipReason' => $row['reason'],
            ];
        }
    }

    /**
     * @param array<int, array{contactId: string, emailAddress: string, contactName: string, targetListIds: string[]}> $audience
     * @param array<string, int> $indexByEmail
     * @param array<string, true> $ambiguousEmails
     */
    private function addAudienceItem(
        array &$audience,
        array &$indexByEmail,
        array &$ambiguousEmails,
        string $contactId,
        string $email,
        string $contactName,
        ?string $targetListId
    ): void {
        if (isset($indexByEmail[$email])) {
            $index = $indexByEmail[$email];

            if ($audience[$index]['contactId'] !== $contactId) {
                $ambiguousEmails[$email] = true;

                return;
            }

            if ($targetListId !== null) {
                $audience[$index]['targetListIds'][] = $targetListId;
            }

            return;
        }

        $indexByEmail[$email] = count($audience);
        $audience[] = [
            'contactId' => $contactId,
            'emailAddress' => $email,
            'contactName' => $contactName,
            'targetListIds' => $targetListId !== null ? [$targetListId] : [],
        ];
    }

    /**
     * Classify contact addresses into sendable vs skipped (no MX).
     * Opted-out / invalid / erased are excluded from both lists.
     *
     * @param array<string, array{sendable: string[], skipped: array<int, array{emailAddress: string, reason: string}>}> $emailsByContactId
     * @return array{sendable: string[], skipped: array<int, array{emailAddress: string, reason: string}>}
     */
    private function classifyEmailsForContact(Entity $contact, array &$emailsByContactId): array
    {
        $contactId = (string) $contact->getId();

        if (isset($emailsByContactId[$contactId])) {
            return $emailsByContactId[$contactId];
        }

        $sendable = [];
        $skipped = [];
        $seen = [];

        /** @var EmailAddressRepository $repo */
        $repo = $this->entityManager->getRepository(EmailAddress::ENTITY_TYPE);

        foreach ($repo->getEmailAddressData($contact) as $row) {
            if (!empty($row->optOut) || !empty($row->invalid)) {
                continue;
            }

            $email = $this->normalizeEmail($row->emailAddress ?? $row->lower ?? null);

            if ($email === null || isset($seen[$email])) {
                continue;
            }

            $seen[$email] = true;

            if ($this->hasValidMx($email)) {
                $sendable[] = $email;
            } else {
                $skipped[] = [
                    'emailAddress' => $email,
                    'reason' => self::SKIP_REASON_NO_MX,
                ];
            }
        }

        if ($sendable === [] && $skipped === []) {
            $primary = $this->normalizeEmail($contact->get('emailAddress'));

            if ($primary !== null && $this->isEmailAddressSendable($primary) && !isset($seen[$primary])) {
                if ($this->hasValidMx($primary)) {
                    $sendable[] = $primary;
                } else {
                    $skipped[] = [
                        'emailAddress' => $primary,
                        'reason' => self::SKIP_REASON_NO_MX,
                    ];
                }
            }
        }

        return $emailsByContactId[$contactId] = [
            'sendable' => $sendable,
            'skipped' => $skipped,
        ];
    }

    /**
     * Every address on the Contact (including opted-out / invalid) for exclusion matching.
     *
     * @param array<string, string[]> $emailsByContactId
     * @return string[]
     */
    private function getAllEmailsForContact(Entity $contact, array &$emailsByContactId): array
    {
        $contactId = 'all:' . $contact->getId();

        if (isset($emailsByContactId[$contactId])) {
            return $emailsByContactId[$contactId];
        }

        $emails = [];
        $seen = [];

        /** @var EmailAddressRepository $repo */
        $repo = $this->entityManager->getRepository(EmailAddress::ENTITY_TYPE);

        foreach ($repo->getEmailAddressData($contact) as $row) {
            $email = $this->normalizeEmail($row->emailAddress ?? $row->lower ?? null);

            if ($email === null || isset($seen[$email])) {
                continue;
            }

            $seen[$email] = true;
            $emails[] = $email;
        }

        $primary = $this->normalizeEmail($contact->get('emailAddress'));

        if ($primary !== null && !isset($seen[$primary])) {
            $emails[] = $primary;
        }

        return $emailsByContactId[$contactId] = $emails;
    }

    /**
     * @param string[] $excludeCampaignIds
     * @param iterable<Entity> $excludingTargetLists
     * @return array<string, true>
     */
    private function collectExcludedEmails(array $excludeCampaignIds, iterable $excludingTargetLists): array
    {
        $excludeEmails = [];

        if ($excludeCampaignIds !== []) {
            $rows = $this->entityManager
                ->getRDBRepository('EmailCampaignContact')
                ->where([
                    'emailCampaignId' => $excludeCampaignIds,
                    'status' => ['Sent'],
                ])
                ->select(['emailAddress'])
                ->find();

            foreach ($rows as $row) {
                $email = $this->normalizeEmail($row->get('emailAddress'));

                if ($email !== null) {
                    $excludeEmails[$email] = true;
                }
            }
        }

        $emailsByContactId = [];

        foreach ($excludingTargetLists as $targetList) {
            $contacts = $this->entityManager
                ->getRDBRepository('TargetList')
                ->getRelation($targetList, 'contacts')
                ->find();

            foreach ($contacts as $contact) {
                foreach ($this->getAllEmailsForContact($contact, $emailsByContactId) as $email) {
                    $excludeEmails[$email] = true;
                }
            }
        }

        return $excludeEmails;
    }

    public function launch(string $campaignId): Entity
    {
        $campaign = $this->entityManager->getEntityById('EmailCampaign', $campaignId);

        if (!$campaign) {
            throw new NotFound("Campaign {$campaignId} not found.");
        }

        if ($campaign->get('status') !== 'Draft') {
            throw new Error("Campaign can only be launched from Draft (current: {$campaign->get('status')}).");
        }

        if (!$campaign->get('emailTemplateId')) {
            throw new Error('Campaign must have an Email Template selected.');
        }

        $template = $this->entityManager->getEntityById('EmailTemplate', $campaign->get('emailTemplateId'));

        if (!$template) {
            throw new Error('Linked Email Template not found.');
        }

        $this->assertOutboundAccount($campaign);
        $this->opportunityService->assertConfiguration($campaign);

        $audience = $this->resolveAudience($campaignId);
        $skipped = $this->getLastSkippedAudience();

        if ($audience === [] && $skipped === [] && !$campaign->get('continuousEnrollment')) {
            throw new Error('Campaign has no valid recipients. Check Target Lists and manual contacts.');
        }

        $createdIds = $this->createCampaignContacts($campaign, $audience);
        $skippedIds = $this->createCampaignContacts(
            $campaign,
            $skipped,
            'Skipped',
            'NotRequested',
            static fn (array $item): ?string => $item['skipReason'] ?? self::SKIP_REASON_NO_MX
        );

        $payload = [
            'status' => 'Sending',
            'totalRecipients' => count($createdIds) + count($skippedIds),
            'skippedCount' => count($skippedIds),
            'startedAt' => date('Y-m-d H:i:s'),
        ];

        // Nothing to send and no continuous enrollment → finish immediately.
        if ($createdIds === [] && !$campaign->get('continuousEnrollment')) {
            $payload['status'] = 'Completed';
            $payload['completedAt'] = date('Y-m-d H:i:s');
        }

        $campaign->set($payload);
        $this->entityManager->saveEntity($campaign);

        $totalChunks = $this->scheduleChunkJobs($campaignId, $createdIds);

        $this->log->info(
            "EmailCampaignService: Launched campaign {$campaignId} with " .
            count($createdIds) . " recipients and " . count($skippedIds) .
            " skipped in {$totalChunks} chunks."
        );

        return $campaign;
    }

    public function enrollNewContacts(string $campaignId): int
    {
        $campaign = $this->entityManager->getEntityById('EmailCampaign', $campaignId);

        if (!$campaign) {
            throw new NotFound("Campaign {$campaignId} not found.");
        }

        if ($campaign->get('status') !== 'Sending' || !$campaign->get('continuousEnrollment')) {
            return 0;
        }

        $audience = $this->resolveAudience($campaignId);
        $skipped = $this->getLastSkippedAudience();

        if ($audience === [] && $skipped === []) {
            return 0;
        }

        return $this->enrollAudience($campaignId, $audience, $skipped);
    }

    /**
     * @param array<int, array{contactId: string, emailAddress: string, contactName: string, targetListIds: string[]}> $audience
     * @param array<int, array{contactId: string, emailAddress: string, contactName: string, targetListIds: string[], skipReason?: string}> $skipped
     */
    public function enrollAudience(string $campaignId, array $audience, array $skipped = []): int
    {
        $campaign = $this->entityManager->getEntityById('EmailCampaign', $campaignId);

        if (!$campaign) {
            throw new NotFound("Campaign {$campaignId} not found.");
        }

        if ($campaign->get('status') !== 'Sending') {
            return 0;
        }

        if ($audience === [] && $skipped === []) {
            return 0;
        }

        $existingRows = $this->entityManager
            ->getRDBRepository('EmailCampaignContact')
            ->where(['emailCampaignId' => $campaignId])
            ->select(['id', 'contactId', 'emailAddress', 'status'])
            ->find();

        $enrolledEmails = [];
        $unsentByEmail = [];

        foreach ($existingRows as $row) {
            $isUnsent = in_array($row->get('status'), ['Pending', 'Retry'], true);
            $email = $this->normalizeEmail($row->get('emailAddress'));

            if ($email === null) {
                continue;
            }

            $enrolledEmails[$email] = true;

            if ($isUnsent) {
                $unsentByEmail[$email] = $row;
            }
        }

        $newAudience = [];

        foreach ($audience as $item) {
            if (!isset($enrolledEmails[$item['emailAddress']])) {
                $newAudience[] = $item;
                // Guard duplicates within the same batch (same address twice).
                $enrolledEmails[$item['emailAddress']] = true;

                continue;
            }

            $row = $unsentByEmail[$item['emailAddress']] ?? null;

            if ($row && !empty($item['targetListIds'])) {
                $this->mergeTargetListsIntoRecipient($row, $item['targetListIds']);
            }
        }

        $newSkipped = [];

        foreach ($skipped as $item) {
            if (isset($enrolledEmails[$item['emailAddress']])) {
                continue;
            }

            $newSkipped[] = $item;
            $enrolledEmails[$item['emailAddress']] = true;
        }

        if ($newAudience === [] && $newSkipped === []) {
            return 0;
        }

        $createdIds = $this->createCampaignContacts($campaign, $newAudience);
        $skippedIds = $this->createCampaignContacts(
            $campaign,
            $newSkipped,
            'Skipped',
            'NotRequested',
            static fn (array $item): ?string => $item['skipReason'] ?? self::SKIP_REASON_NO_MX
        );

        if ($createdIds === [] && $skippedIds === []) {
            return 0;
        }

        $totalRows = $this->entityManager
            ->getRDBRepository('EmailCampaignContact')
            ->where(['emailCampaignId' => $campaignId])
            ->count();

        $skippedCount = $this->entityManager
            ->getRDBRepository('EmailCampaignContact')
            ->where(['emailCampaignId' => $campaignId, 'status' => 'Skipped'])
            ->count();

        $campaign->set([
            'totalRecipients' => $totalRows,
            'skippedCount' => $skippedCount,
        ]);
        $this->entityManager->saveEntity($campaign);

        if ($createdIds !== []) {
            $this->scheduleChunkJobs($campaignId, $createdIds);
        }

        return count($createdIds) + count($skippedIds);
    }

    /**
     * @param string[] $targetListIds
     */
    private function mergeTargetListsIntoRecipient(Entity $row, array $targetListIds): void
    {
        try {
            $relation = $this->entityManager
                ->getRDBRepository('EmailCampaignContact')
                ->getRelation($row, 'targetLists');

            foreach ($targetListIds as $targetListId) {
                if (!$relation->isRelatedById($targetListId)) {
                    $relation->relateById($targetListId);
                }
            }
        } catch (\Throwable $e) {
            $this->log->warning(
                "EmailCampaignService: Could not merge Target List provenance into " .
                "recipient {$row->getId()}: {$e->getMessage()}"
            );
        }
    }

    public function stopEnrollment(string $campaignId): Entity
    {
        $campaign = $this->entityManager->getEntityById('EmailCampaign', $campaignId);

        if (!$campaign) {
            throw new NotFound("Campaign {$campaignId} not found.");
        }

        if ($campaign->get('status') !== 'Sending' || !$campaign->get('continuousEnrollment')) {
            throw new Forbidden('Campaign is not a running campaign with continuous enrollment enabled.');
        }

        $campaign->set('continuousEnrollment', false);

        $pendingOrRetryCount = $this->entityManager
            ->getRDBRepository('EmailCampaignContact')
            ->where([
                'emailCampaignId' => $campaignId,
                'status' => ['Pending', 'Retry', 'Processing'],
            ])
            ->count();

        if ($pendingOrRetryCount === 0) {
            $campaign->set([
                'status' => 'Completed',
                'completedAt' => date('Y-m-d H:i:s'),
            ]);
        }

        $this->entityManager->saveEntity($campaign);

        return $campaign;
    }

    public function abort(string $campaignId): Entity
    {
        $campaign = $this->entityManager->getEntityById('EmailCampaign', $campaignId);

        if (!$campaign) {
            throw new NotFound("Campaign {$campaignId} not found.");
        }

        $status = $campaign->get('status');

        if (!in_array($status, ['Sending', 'Scheduled'], true)) {
            throw new Forbidden(
                "Campaign can only be aborted from Sending or Scheduled status (current: {$status})."
            );
        }

        $campaign->set([
            'status' => 'Cancelled',
            'completedAt' => date('Y-m-d H:i:s'),
            'continuousEnrollment' => false,
        ]);
        $this->entityManager->saveEntity($campaign);

        return $campaign;
    }

    /**
     * @param array<int, array{contactId: string, emailAddress: string, contactName: string, targetListIds: string[], skipReason?: string}> $audience
     * @param (callable(array): (?string))|null $failedReasonResolver
     * @return string[]
     */
    private function createCampaignContacts(
        Entity $campaign,
        array $audience,
        string $status = 'Pending',
        string $opportunityAttributionStatus = 'auto',
        ?callable $failedReasonResolver = null
    ): array {
        $createdIds = [];

        if ($opportunityAttributionStatus === 'auto') {
            $opportunityAttributionStatus = $campaign->get('createOpportunity') && $status === 'Pending'
                ? 'Pending'
                : 'NotRequested';
        }

        foreach ($audience as $item) {
            $transactionManager = $this->entityManager->getTransactionManager();
            $transactionManager->start();

            try {
                $payload = [
                    'emailCampaignId' => $campaign->getId(),
                    'contactId' => $item['contactId'],
                    'emailAddress' => $item['emailAddress'],
                    'contactName' => $item['contactName'],
                    'status' => $status,
                    'opportunityAttributionStatus' => $opportunityAttributionStatus,
                ];

                if ($failedReasonResolver !== null) {
                    $reason = $failedReasonResolver($item);

                    if ($reason !== null) {
                        $payload['failedReason'] = substr($reason, 0, 5000);
                        $payload['failedAt'] = date('Y-m-d H:i:s');
                    }
                }

                $entity = $this->entityManager->createEntity('EmailCampaignContact', $payload);

                foreach ($item['targetListIds'] ?? [] as $targetListId) {
                    $this->entityManager
                        ->getRDBRepository('EmailCampaignContact')
                        ->getRelation($entity, 'targetLists')
                        ->relateById($targetListId);
                }

                $transactionManager->commit();
                $createdIds[] = $entity->getId();
            } catch (\Throwable $e) {
                $transactionManager->rollback();
                $this->log->warning(
                    "EmailCampaignService: Skipped enrolling contact {$item['contactId']} " .
                    "into campaign {$campaign->getId()}: {$e->getMessage()}"
                );
            }
        }

        return $createdIds;
    }

    /**
     * @param string[] $campaignContactIds
     */
    private function scheduleChunkJobs(string $campaignId, array $campaignContactIds): int
    {
        $chunks = array_chunk($campaignContactIds, self::CHUNK_SIZE);

        foreach ($chunks as $chunk) {
            $this->jobSchedulerFactory
                ->create()
                ->setClassName('Espo\\Modules\\FeatureEmailCampaign\\Jobs\\ProcessEmailCampaignChunk')
                ->setData([
                    'campaignId' => $campaignId,
                    'campaignContactIds' => $chunk,
                ])
                ->schedule();
        }

        return count($chunks);
    }

    private function assertOutboundAccount(Entity $campaign): void
    {
        $emailAccountId = $campaign->get('emailAccountId');
        $inboundEmailId = $campaign->get('inboundEmailId');

        if ($emailAccountId && $inboundEmailId) {
            throw new Error('Choose either a Personal Email Account or a Group Email Account, not both.');
        }

        if ($emailAccountId) {
            $account = $this->entityManager->getEntityById('EmailAccount', $emailAccountId);

            if (!$account) {
                throw new Error('Linked Personal Email Account not found.');
            }

            if ($account->get('status') !== 'Active' || !$account->get('useSmtp') || !$account->get('smtpHost')) {
                throw new Error('Personal Email Account must be Active with SMTP enabled.');
            }

            return;
        }

        if (!$inboundEmailId) {
            return;
        }

        $account = $this->entityManager->getEntityById('InboundEmail', $inboundEmailId);

        if (!$account) {
            throw new Error('Linked Group Email Account not found.');
        }

        if (
            !$account->get('useSmtp') ||
            !$account->get('smtpIsForMassEmail') ||
            !$account->get('smtpHost')
        ) {
            throw new Error(
                'Group Email Account is not configured for mass email (use SMTP + "Use for Mass Email").'
            );
        }
    }

    private function normalizeEmail(mixed $email): ?string
    {
        $email = strtolower(trim((string) ($email ?? '')));

        if ($email === '' || str_starts_with($email, 'erased:')) {
            return null;
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        return $email;
    }

    private function isEmailAddressSendable(string $email): bool
    {
        /** @var EmailAddressRepository $repo */
        $repo = $this->entityManager->getRepository(EmailAddress::ENTITY_TYPE);
        $entity = $repo->getByAddress($email);

        if (!$entity) {
            return true;
        }

        if ($entity->get('invalid') || $entity->get('optOut')) {
            return false;
        }

        return true;
    }

    /**
     * Skip domains with no MX (or A/AAAA fallback) to avoid hard bounces.
     */
    private function hasValidMx(string $email): bool
    {
        if (EmailDomainMxValidator::emailDomainHasValidMx($email)) {
            return true;
        }

        $this->log->info(
            "EmailCampaignService: Skipping {$email} — domain has no valid MX/A record."
        );

        return false;
    }
}

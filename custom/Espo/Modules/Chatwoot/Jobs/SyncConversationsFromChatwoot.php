<?php

namespace Espo\Modules\Chatwoot\Jobs;

use Espo\Core\Job\JobDataLess;
use Espo\Core\InjectableFactory;
use Espo\Core\Utils\Log;
use Espo\ORM\EntityManager;
use Espo\ORM\Entity;
use Espo\Modules\Chatwoot\Services\ChatwootApiClient;
use Espo\Modules\Chatwoot\Services\WahaApiClient;
use Espo\Modules\Chatwoot\Tools\ContactReconciler;

/**
 * Scheduled job to sync conversations from Chatwoot to EspoCRM.
 * Iterates through all ChatwootAccount records with contactSyncEnabled = true
 * and pulls conversations from Chatwoot.
 */
class SyncConversationsFromChatwoot implements JobDataLess
{
    private const MAX_PAGES_PER_RUN = 50;
    private const PAGE_SIZE = 25; // Chatwoot conversations API page size

    /**
     * WAHA color map (0-19).
     */
    private const COLOR_MAP = [
        0 => '#ff9485', 1 => '#64c4ff', 2 => '#ffd429', 3 => '#dfaef0',
        4 => '#99b6c1', 5 => '#55ccb3', 6 => '#ff9dff', 7 => '#d3a91d',
        8 => '#6d7cce', 9 => '#d7e752', 10 => '#00d0e2', 11 => '#ffc5c7',
        12 => '#93ceac', 13 => '#f74848', 14 => '#00a0f2', 15 => '#83e422',
        16 => '#ffaf04', 17 => '#b5ebff', 18 => '#9ba6ff', 19 => '#9368cf',
    ];

    public function __construct(
        private EntityManager $entityManager,
        private ChatwootApiClient $apiClient,
        private WahaApiClient $wahaApiClient,
        private ContactReconciler $reconciler,
        private InjectableFactory $injectableFactory,
        private Log $log
    ) {}

    public function run(): void
    {
        $this->log->debug('SyncConversationsFromChatwoot: Job started');

        try {
            $accounts = $this->getEnabledAccounts();
            $accountList = iterator_to_array($accounts);
            $accountCount = count($accountList);

            $this->log->debug("SyncConversationsFromChatwoot: Found {$accountCount} account(s) to sync");

            foreach ($accountList as $account) {
                $this->syncAccountConversations($account);
            }

            $this->log->debug("SyncConversationsFromChatwoot: Job completed - processed {$accountCount} account(s)");
        } catch (\Throwable $e) {
            $this->log->error('SyncConversationsFromChatwoot: Job failed - ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
        }
    }

    /**
     * Get all ChatwootAccounts with contact sync enabled.
     *
     * @return iterable<Entity>
     */
    private function getEnabledAccounts(): iterable
    {
        return $this->entityManager
            ->getRDBRepository('ChatwootAccount')
            ->where([
                'contactSyncEnabled' => true,
                'status' => 'active',
            ])
            ->find();
    }

    /**
     * Sync conversations for a single ChatwootAccount.
     */
    private function syncAccountConversations(Entity $account): void
    {
        $accountName = $account->get('name');

        try {
            $platform = $this->entityManager->getEntityById(
                'ChatwootPlatform',
                $account->get('platformId')
            );

            if (!$platform) {
                throw new \Exception('ChatwootPlatform not found');
            }

            $platformUrl = $platform->get('backendUrl');
            $apiKey = $account->get('apiKey');
            $chatwootAccountId = $account->get('chatwootAccountId');

            if (!$platformUrl || !$apiKey || !$chatwootAccountId) {
                throw new \Exception('Missing platform URL, API key, or Chatwoot account ID');
            }

            // Get cursor for incremental sync
            $cursor = $account->get('conversationSyncCursor');

            // Get teams from the ChatwootAccount
            $teamsIds = $this->getAccountTeamsIds($account);
            $teamId = !empty($teamsIds) ? $teamsIds[0] : null;
            $tenantId = $this->normalizeTenantId($account->get('tenantId'));

            // Sync conversations
            $result = $this->syncConversations(
                $platformUrl,
                $apiKey,
                $chatwootAccountId,
                $account->getId(),
                $cursor,
                $teamId,
                $tenantId,
                $teamsIds
            );

            // Update sync timestamps and cursor
            $account->set('lastConversationSyncAt', date('Y-m-d H:i:s'));
            if ($result['newCursor'] !== null) {
                $account->set('conversationSyncCursor', $result['newCursor']);
            }
            $this->entityManager->saveEntity($account, ['silent' => true]);

            $this->log->debug(
                "SyncConversationsFromChatwoot: Account {$accountName} - " .
                "{$result['synced']} synced, {$result['skipped']} skipped, {$result['errors']} errors" .
                ($result['hasMore'] ? " (more pages remaining)" : " (complete)")
            );

            // Run reconciliation when sync is complete (no more pages)
            // This detects conversations that were deleted in Chatwoot
            if (!$result['hasMore']) {
                $this->reconcileDeletedConversations(
                    $platformUrl,
                    $apiKey,
                    $chatwootAccountId,
                    $account->getId()
                );
            }

        } catch (\Exception $e) {
            $this->log->error(
                "Chatwoot conversation sync failed for account {$accountName}: " . $e->getMessage()
            );
        }
    }

    /**
     * Sync conversations from Chatwoot to EspoCRM using cursor-based incremental sync.
     *
     * @param string $platformUrl
     * @param string $apiKey
     * @param int $chatwootAccountId
     * @param string $espoAccountId
     * @param int|null $cursor Unix timestamp of last synced conversation's last_activity_at
     * @param string|null $teamId Team ID to assign to synced entities
     * @return array{synced: int, skipped: int, errors: int, newCursor: int|null, hasMore: bool}
     */
    private function syncConversations(
        string $platformUrl,
        string $apiKey,
        int $chatwootAccountId,
        string $espoAccountId,
        ?int $cursor = null,
        ?string $teamId = null,
        ?string $tenantId = null,
        array $teamsIds = []
    ): array {
        $stats = ['synced' => 0, 'skipped' => 0, 'errors' => 0, 'newCursor' => $cursor, 'hasMore' => false];
        $page = 1;
        $pagesProcessed = 0;
        $maxLastActivityAt = $cursor;

        // Build filter for incremental sync
        // Chatwoot filter uses date-only comparison, so we subtract 1 day 
        // to ensure we don't miss same-day updates (may re-sync some records)
        $filters = [];
        if ($cursor !== null) {
            // Subtract 1 day to catch same-day updates
            $cursorDate = date('Y-m-d', $cursor - 86400);
            $filters[] = [
                'attribute_key' => 'last_activity_at',
                'filter_operator' => 'is_greater_than',
                'values' => [$cursorDate],
                'query_operator' => null
            ];
        }

        $this->log->debug(
            "SyncConversationsFromChatwoot: Starting sync with cursor=" .
            ($cursor !== null ? date('Y-m-d H:i:s', $cursor) . " ({$cursor})" : 'null')
        );

        do {
            $response = $this->apiClient->filterConversations(
                $platformUrl,
                $apiKey,
                $chatwootAccountId,
                $page,
                $filters
            );

            // Handle response structure - filter API returns {meta, payload} directly
            $conversations = $response['payload'] ?? [];
            $meta = $response['meta'] ?? [];

            // Get counts for pagination
            $allCount = $meta['all_count'] ?? count($conversations);

            $this->log->debug(
                "SyncConversationsFromChatwoot: Page {$page} - " . count($conversations) .
                " conversations, total: {$allCount}"
            );

            foreach ($conversations as $chatwootConversation) {
                try {
                    $result = $this->syncSingleConversation($chatwootConversation, $espoAccountId, $teamId, $tenantId, $teamsIds);

                    if ($result === 'synced') {
                        $stats['synced']++;
                    } else {
                        $stats['skipped']++;
                    }

                    // Track max last_activity_at for cursor update
                    $convLastActivity = $chatwootConversation['last_activity_at'] ?? null;
                    if ($convLastActivity !== null) {
                        if ($maxLastActivityAt === null || $convLastActivity > $maxLastActivityAt) {
                            $maxLastActivityAt = $convLastActivity;
                        }
                    }
                } catch (\Exception $e) {
                    $stats['errors']++;
                    $conversationId = $chatwootConversation['id'] ?? 'unknown';
                    $this->log->debug(
                        "Failed to sync Chatwoot conversation {$conversationId}: " . $e->getMessage()
                    );
                }
            }

            $page++;
            $pagesProcessed++;

            $totalPages = (int) ceil($allCount / self::PAGE_SIZE);
            $hasMorePages = $page <= $totalPages;

            // Stop if we've processed enough pages this run (prevent timeout)
            if ($pagesProcessed >= self::MAX_PAGES_PER_RUN) {
                $stats['hasMore'] = $hasMorePages;
                break;
            }

        } while ($hasMorePages && count($conversations) > 0);

        // Update cursor to max last_activity_at seen
        $stats['newCursor'] = $maxLastActivityAt;

        return $stats;
    }

    /**
     * Sync a single conversation from Chatwoot to EspoCRM.
     *
     * @param string|null $teamId Team ID to assign to synced entities
     * @return string 'synced' or 'skipped'
     */
    private function syncSingleConversation(array $chatwootConversation, string $espoAccountId, ?string $teamId = null, ?string $tenantId = null, array $teamsIds = []): string
    {
        $chatwootConversationId = (int) $chatwootConversation['id'];
        $inboxId = isset($chatwootConversation['inbox_id']) ? (int) $chatwootConversation['inbox_id'] : null;
        $contactId = isset($chatwootConversation['meta']['sender']['id']) ? (int) $chatwootConversation['meta']['sender']['id'] : null;

        if (!$inboxId || !$contactId) {
            $this->log->debug("SyncConversationsFromChatwoot: Skipping conversation {$chatwootConversationId} - missing inboxId or contactId");
            return 'skipped';
        }

        // Find the ChatwootContact for this conversation (including soft-deleted records)
        $cwtContact = $this->findEntityIncludingDeleted('ChatwootContact', [
            'chatwootContactId' => $contactId,
            'chatwootAccountId' => $espoAccountId,
        ]);

        if (!$cwtContact) {
            // Contact doesn't exist - create it on-the-fly from conversation sender data
            // This handles contacts that don't appear in the contacts filter API (e.g., Instagram contacts)
            $senderData = $chatwootConversation['meta']['sender'] ?? null;
            if (!$senderData) {
                $this->log->debug("SyncConversationsFromChatwoot: Skipping conversation {$chatwootConversationId} - no sender data available");
                return 'skipped';
            }

            $this->log->info("SyncConversationsFromChatwoot: Creating ChatwootContact on-the-fly for contact ID {$contactId} (conversation {$chatwootConversationId})");
            // Pass the conversation's inbox id so the reconciler can
            // attribute the channel identity to the right inbox even
            // when the sender payload doesn't carry contact_inboxes.
            $cwtContact = $this->createContactFromSenderData(
                $senderData,
                $espoAccountId,
                $teamId,
                $tenantId,
                $teamsIds,
                $inboxId,
                $chatwootConversation['meta']['channel'] ?? null
            );

            if (!$cwtContact) {
                $this->log->error("SyncConversationsFromChatwoot: Failed to create ChatwootContact for contact ID {$contactId}");
                return 'skipped';
            }
        } else {
            // Restore soft-deleted contact using the proper EspoCRM method
            $this->entityManager
                ->getRDBRepository('ChatwootContact')
                ->restoreDeleted($cwtContact->getId());
            
            // Re-fetch the entity after restoration
            $cwtContact = $this->entityManager->getEntityById('ChatwootContact', $cwtContact->getId());
        }

        // Find the ChatwootInbox entity for linking
        $chatwootInbox = $this->entityManager
            ->getRDBRepository('ChatwootInbox')
            ->where([
                'chatwootInboxId' => $inboxId,
                'chatwootAccountId' => $espoAccountId,
            ])
            ->findOne();

        // Find or create the ChatwootContactInbox for this conversation
        $contactInbox = $this->entityManager
            ->getRDBRepository('ChatwootContactInbox')
            ->where([
                'chatwootContactId' => $cwtContact->getId(),
                'chatwootInboxId' => $inboxId,
                'chatwootAccountId' => $espoAccountId,
            ])
            ->findOne();

        // Create ChatwootContactInbox if it doesn't exist (for contacts created on-the-fly)
        if (!$contactInbox && $chatwootInbox) {
            $contactInbox = $this->createContactInboxFromConversation(
                $cwtContact,
                $chatwootInbox,
                $chatwootConversation,
                $espoAccountId,
                $teamId
            );
        }

        // Check if ChatwootConversation already exists (including soft-deleted)
        $existingConversation = $this->findEntityIncludingDeleted('ChatwootConversation', [
            'chatwootConversationId' => $chatwootConversationId,
            'chatwootAccountId' => $espoAccountId,
        ]);

        $conversation = null;
        $result = 'skipped';

        if ($existingConversation) {
            // Restore soft-deleted conversation using the proper EspoCRM method
            $this->entityManager
                ->getRDBRepository('ChatwootConversation')
                ->restoreDeleted($existingConversation->getId());
            
            // Re-fetch the entity after restoration
            $existingConversation = $this->entityManager->getEntityById('ChatwootConversation', $existingConversation->getId());
            
            $result = $this->updateExistingConversation($existingConversation, $chatwootConversation, $cwtContact, $contactInbox, $chatwootInbox, $teamId);
            $conversation = $existingConversation;
        } else {
            $result = $this->createNewConversation($chatwootConversation, $espoAccountId, $cwtContact, $contactInbox, $chatwootInbox, $teamId);
            // Find the newly created conversation
            $conversation = $this->entityManager
                ->getRDBRepository('ChatwootConversation')
                ->where([
                    'chatwootConversationId' => $chatwootConversationId,
                    'chatwootAccountId' => $espoAccountId,
                ])
                ->findOne();
        }

        // Sync messages for this conversation
        if ($conversation && $result === 'synced') {
            $messages = $chatwootConversation['messages'] ?? [];
            if (!empty($messages)) {
                $this->syncMessages($messages, $conversation, $cwtContact, $espoAccountId, $teamId);
            }
        }

        // Ingest any Click-to-WhatsApp / Instagram conversion events carried
        // in the conversation's additional_attributes.ctwa (captured by the
        // Chatwoot-side ctwa patches). Idempotent on wamid; safe no-op when
        // absent or when the FeatureMetaConversionsApi module is not present.
        if ($conversation) {
            $this->ingestConversionEvents(
                $chatwootConversation,
                $conversation,
                $cwtContact,
                $tenantId,
                $teamsIds,
            );
        }

        return $result;
    }

    /**
     * Hand the conversation's additional_attributes.ctwa to the
     * FeatureMetaConversionsApi ingester, if that module is installed.
     *
     * Resolved lazily by FQCN so the Chatwoot module carries no hard
     * dependency on the CAPI module.
     *
     * @param array<string, mixed> $chatwootConversation Raw Chatwoot payload.
     * @param array<string> $teamsIds
     */
    private function ingestConversionEvents(
        array $chatwootConversation,
        Entity $conversation,
        ?Entity $cwtContact,
        ?string $tenantId,
        array $teamsIds
    ): void {
        $additionalAttributes = $chatwootConversation['additional_attributes'] ?? null;

        if (!is_array($additionalAttributes) || empty($additionalAttributes['ctwa'])) {
            return;
        }

        $ingesterClass = 'Espo\\Modules\\FeatureMetaConversionsApi\\Services\\ConversionEventIngester';

        if (!class_exists($ingesterClass)) {
            return;
        }

        try {
            $ingester = $this->injectableFactory->create($ingesterClass);

            $created = $ingester->ingest(
                $additionalAttributes,
                $conversation,
                $cwtContact,
                $tenantId,
                $teamsIds,
            );

            if ($created > 0) {
                $this->log->info(sprintf(
                    'SyncConversationsFromChatwoot: ingested %d Meta conversion event(s) for conversation %s.',
                    $created,
                    (string) $conversation->getId(),
                ));
            }
        } catch (\Throwable $e) {
            $this->log->error(
                'SyncConversationsFromChatwoot: conversion-event ingest failed for conversation '
                . (string) $conversation->getId() . ': ' . $e->getMessage()
            );
        }
    }

    /**
     * Create a ChatwootContact from conversation sender data.
     * This handles contacts that don't appear in the contacts filter API (e.g., Instagram contacts).
     *
     * The EspoCRM Contact-side dedup / auto-provision goes through
     * {@see ContactReconciler}, which:
     *   - matches by (tenantId, channelType, sourceId) so Instagram /
     *     Telegram contacts with no phone or email still resolve to an
     *     existing Contact via their channel identity;
     *   - auto-creates a phoneless/emailless Contact when nothing
     *     matches (the previous code skipped this case);
     *   - materializes ContactChannelIdentity rows so future syncs hit
     *     the fast path.
     *
     * @param array $senderData Sender data from conversation meta
     * @param string $espoAccountId EspoCRM ChatwootAccount ID
     * @param string|null $teamId Primary team ID to assign
     * @param ?string $tenantId Tenant id derived from the ChatwootAccount
     * @param array<string> $teamsIds All teams from the ChatwootAccount
     * @param ?int $conversationInboxId Chatwoot inbox id (int) the
     *   conversation is in, used as the identity's originating inbox
     *   when the sender payload has no `contact_inboxes`.
     * @param ?string $conversationChannel Chatwoot meta.channel string
     *   (e.g. "Channel::Instagram") for the same use.
     * @return Entity|null The created ChatwootContact, or null on failure
     */
    private function createContactFromSenderData(
        array $senderData,
        string $espoAccountId,
        ?string $teamId = null,
        ?string $tenantId = null,
        array $teamsIds = [],
        ?int $conversationInboxId = null,
        ?string $conversationChannel = null
    ): ?Entity {
        $chatwootContactId = (int) ($senderData['id'] ?? 0);
        if (!$chatwootContactId) {
            return null;
        }

        if (empty($teamsIds) && $teamId) {
            $teamsIds = [$teamId];
        }

        // Check again to prevent race conditions
        $existingCwtContact = $this->findEntityIncludingDeleted('ChatwootContact', [
            'chatwootContactId' => $chatwootContactId,
            'chatwootAccountId' => $espoAccountId,
        ]);

        if ($existingCwtContact) {
            $this->entityManager
                ->getRDBRepository('ChatwootContact')
                ->restoreDeleted($existingCwtContact->getId());
            return $this->entityManager->getEntityById('ChatwootContact', $existingCwtContact->getId());
        }

        $name = $senderData['name'] ?? null;
        $phoneNumber = $senderData['phone_number'] ?? null;
        $email = $senderData['email'] ?? null;
        $avatarUrl = $senderData['thumbnail'] ?? null;
        $identifier = $senderData['identifier'] ?? null;
        $blocked = $senderData['blocked'] ?? false;
        $lastActivityAt = $senderData['last_activity_at'] ?? null;
        $createdAt = $senderData['created_at'] ?? null;

        // Build a synthetic contact_inboxes entry from the
        // conversation so the reconciler can register a channel
        // identity even when the sender payload omits the linkage.
        $contactInboxes = $senderData['contact_inboxes'] ?? [];
        if (!empty($identifier) && $conversationInboxId && $conversationChannel) {
            $contactInboxes[] = [
                'source_id' => $identifier,
                'inbox' => [
                    'id' => $conversationInboxId,
                    'channel_type' => $conversationChannel,
                ],
            ];
        }

        // Reconcile against the CRM Contact side BEFORE creating the
        // bridge so the bridge row carries the resolved contactId.
        $reconciled = $this->reconciler->reconcile([
            'tenantId' => $tenantId,
            'teamsIds' => $teamsIds,
            'chatwootAccountId' => $espoAccountId,
            'name' => $name,
            'phoneNumber' => $phoneNumber,
            'email' => $email,
            'identifier' => $identifier,
            'contactInboxes' => $contactInboxes,
            'inboxIdMap' => $this->buildInboxIdMap($contactInboxes, $espoAccountId),
        ]);
        $espoContact = $reconciled['contact'];

        $data = [
            'chatwootContactId' => $chatwootContactId,
            'chatwootAccountId' => $espoAccountId,
            'contactId' => $espoContact?->getId(),
            'name' => $name,
            'phoneNumber' => $phoneNumber,
            'email' => $email,
            'identifier' => $identifier,
            'blocked' => $blocked,
            'avatarUrl' => $avatarUrl,
            'chatwootLastActivityAt' => $this->convertChatwootTimestamp($lastActivityAt),
            'chatwootCreatedAt' => $this->convertChatwootTimestamp($createdAt),
            'syncStatus' => 'synced',
            'lastSyncedAt' => date('Y-m-d H:i:s'),
        ];

        if (!empty($teamsIds)) {
            $data['teamsIds'] = $teamsIds;
        }

        try {
            $cwtContact = $this->entityManager->createEntity('ChatwootContact', $data, ['silent' => true]);

            if (!empty($teamsIds)) {
                $cwtContact->set('teamsIds', $teamsIds);
                $this->entityManager->saveEntity($cwtContact, ['silent' => true]);
            }

            $this->log->info(
                "SyncConversationsFromChatwoot: Created ChatwootContact {$cwtContact->getId()} "
                . "for Chatwoot contact {$chatwootContactId} "
                . "(reconciler matchedBy={$reconciled['matchedBy']}, "
                . "espoContactId=" . ($espoContact?->getId() ?? 'null') . ")"
            );
            return $cwtContact;

        } catch (\Exception $e) {
            $this->log->error("SyncConversationsFromChatwoot: Failed to create ChatwootContact for {$chatwootContactId}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Build a map of Chatwoot inbox id (int, from the payload) → EspoCRM
     * ChatwootInbox entity id (varchar 17). Used by the reconciler.
     *
     * @param list<array{inbox?: array{id?: ?int}}> $contactInboxes
     * @return array<int, string>
     */
    private function buildInboxIdMap(array $contactInboxes, string $espoAccountId): array
    {
        $rawIds = [];
        foreach ($contactInboxes as $ci) {
            $id = $ci['inbox']['id'] ?? null;
            if (is_int($id) || (is_string($id) && ctype_digit($id))) {
                $rawIds[] = (int) $id;
            }
        }
        if ($rawIds === []) {
            return [];
        }
        $rawIds = array_values(array_unique($rawIds));

        $rows = $this->entityManager
            ->getRDBRepository('ChatwootInbox')
            ->select(['id', 'chatwootInboxId'])
            ->where([
                'chatwootInboxId' => $rawIds,
                'chatwootAccountId' => $espoAccountId,
            ])
            ->find();

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row->get('chatwootInboxId')] = $row->getId();
        }
        return $map;
    }

    /**
     * Normalize a ChatwootAccount.tenantId string. Older rows defaulted
     * to the literal "NULL" via Drizzle; treat those as missing.
     */
    private function normalizeTenantId(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $trimmed = trim($value);
        if ($trimmed === '' || $trimmed === 'NULL' || $trimmed === '0') {
            return null;
        }
        return $trimmed;
    }

    /**
     * Create a ChatwootContactInbox from conversation data.
     * This creates the link between ChatwootContact and ChatwootInbox for contacts created on-the-fly.
     */
    private function createContactInboxFromConversation(
        Entity $cwtContact,
        Entity $chatwootInbox,
        array $chatwootConversation,
        string $espoAccountId,
        ?string $teamId = null
    ): ?Entity {
        $inboxId = (int) ($chatwootConversation['inbox_id'] ?? 0);
        $channel = $chatwootConversation['meta']['channel'] ?? null;
        $channelType = $chatwootInbox->get('channelType');
        
        // Map channel type to our enum
        $mappedChannelType = $this->mapChannelType($channelType);
        
        // Generate display name
        $contactName = $cwtContact->get('name') ?? 'Unknown';
        $inboxName = $chatwootInbox->get('name') ?? $channel ?? 'Inbox #' . $inboxId;
        if ($mappedChannelType) {
            $inboxName .= ' (' . $mappedChannelType . ')';
        }
        $name = $contactName . ' <> ' . $inboxName;

        $data = [
            'name' => $name,
            'chatwootContactId' => $cwtContact->getId(),
            'contactId' => $cwtContact->get('contactId'),
            'chatwootAccountId' => $espoAccountId,
            'chatwootInboxId' => $inboxId,
            'inboxId' => $chatwootInbox->getId(),
            'inboxName' => $chatwootInbox->get('name') ?? $channel,
            'inboxChannelType' => $mappedChannelType,
            'lastSyncedAt' => date('Y-m-d H:i:s'),
        ];

        if ($teamId) {
            $data['teamsIds'] = [$teamId];
        }

        try {
            $contactInbox = $this->entityManager->createEntity('ChatwootContactInbox', $data, ['silent' => true]);
            
            // Explicitly set teams after creation (linkMultiple requires explicit save)
            if ($teamId) {
                $contactInbox->set('teamsIds', [$teamId]);
                $this->entityManager->saveEntity($contactInbox, ['silent' => true]);
            }
            
            $this->log->info("SyncConversationsFromChatwoot: Created ChatwootContactInbox {$contactInbox->getId()} for contact {$cwtContact->getId()} and inbox {$inboxId}");
            return $contactInbox;
        } catch (\Exception $e) {
            $this->log->error("SyncConversationsFromChatwoot: Failed to create ChatwootContactInbox: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Resolve the Instagram-scoped user id (IGSID) for a conversation.
     *
     * Only returned for Instagram conversations. The durable source is the
     * ChatwootContactInbox.sourceId (always populated by the inbox sync);
     * we fall back to the raw payload's sender.identifier when the bridge
     * row isn't available yet.
     *
     * @param array<string, mixed> $chatwootConversation Raw Chatwoot payload.
     */
    private function resolveIgUserId(
        array $chatwootConversation,
        ?Entity $contactInbox,
        ?Entity $chatwootInbox
    ): ?string {
        // Determine channel from the inbox entity first, then the payload.
        $channelType = $chatwootInbox?->get('channelType')
            ?? ($chatwootConversation['meta']['channel'] ?? null);
        $mapped = $this->mapChannelType($channelType);

        if ($mapped !== 'instagram') {
            return null;
        }

        $sourceId = $contactInbox?->get('sourceId');
        if (is_string($sourceId) && $sourceId !== '') {
            return $sourceId;
        }

        $identifier = $chatwootConversation['meta']['sender']['identifier'] ?? null;
        if (is_string($identifier) && $identifier !== '') {
            return $identifier;
        }

        return null;
    }

    /**
     * Map Chatwoot channel_type to our enum values.
     */
    private function mapChannelType(?string $channelType): ?string
    {
        if (!$channelType) {
            return null;
        }

        $map = [
            'Channel::Whatsapp' => 'whatsapp',
            'Channel::Email' => 'email',
            'Channel::WebWidget' => 'web_widget',
            'Channel::Api' => 'api',
            'Channel::Telegram' => 'telegram',
            'Channel::Sms' => 'sms',
            'Channel::FacebookPage' => 'facebook',
            'Channel::Instagram' => 'instagram',
        ];

        return $map[$channelType] ?? strtolower(str_replace('Channel::', '', $channelType));
    }

    /**
     * Update an existing ChatwootConversation from Chatwoot data.
     *
     * @param string|null $teamId Team ID to assign to synced entities
     */
    private function updateExistingConversation(
        Entity $conversation,
        array $chatwootConversation,
        Entity $cwtContact,
        ?Entity $contactInbox,
        ?Entity $chatwootInbox,
        ?string $teamId = null
    ): string {
        // Extract assignee info (can be null)
        $assignee = $chatwootConversation['meta']['assignee'] ?? null;
        $sender = $chatwootConversation['meta']['sender'] ?? null;
        $channel = $chatwootConversation['meta']['channel'] ?? null;

        // Track assigneeId change for label update
        $oldAssigneeId = $conversation->get('assigneeId');
        $newAssigneeId = $assignee['id'] ?? null;
        $assigneeChanged = $oldAssigneeId !== $newAssigneeId;

        // Generate display name
        $contactName = $sender['name'] ?? $cwtContact->get('name') ?? '';
        $name = $contactName ?: 'Conversation #' . $chatwootConversation['id'];

        $conversation->set('name', $name);
        $conversation->set('status', $chatwootConversation['status'] ?? 'open');
        $conversation->set('chatwootInboxId', $chatwootConversation['inbox_id'] ?? null);
        $conversation->set('inboxName', $channel);
        $conversation->set('assigneeId', $newAssigneeId);
        $conversation->set('assigneeName', $assignee['name'] ?? $assignee['available_name'] ?? null);
        $conversation->set('lastActivityAt', $this->convertChatwootTimestamp($chatwootConversation['last_activity_at'] ?? null));
        $conversation->set('lastSyncedAt', date('Y-m-d H:i:s'));

        // Set denormalized fields for kanban card display
        $conversation->set('contactDisplayName', $contactName);
        $conversation->set('contactAvatarUrl', $cwtContact->get('avatarUrl'));
        $conversation->set('contactPhoneNumber', $cwtContact->get('phoneNumber'));
        
        // Get last message content and type from messages array
        $messages = $chatwootConversation['messages'] ?? [];
        if (!empty($messages)) {
            $lastMessage = end($messages);
            $lastMessageContent = $lastMessage['content'] ?? '';
            $conversation->set('lastMessageContent', mb_substr(strip_tags($lastMessageContent), 0, 200));
            // Map message_type: 0=incoming, 1=outgoing, 2=activity, 3=template
            $messageTypeMap = [0 => 'incoming', 1 => 'outgoing', 2 => 'activity', 3 => 'template'];
            $lastMessageType = $messageTypeMap[$lastMessage['message_type'] ?? 0] ?? 'incoming';
            $conversation->set('lastMessageType', $lastMessageType);
        }
        
        // Get inbox channel type
        if ($chatwootInbox) {
            $conversation->set('inboxChannelType', $chatwootInbox->get('channelType'));
        }

        // Persist the Instagram-scoped user id (IGSID) so it is filterable,
        // sortable and exportable via Reports. Only meaningful for Instagram
        // conversations; left null otherwise.
        $conversation->set(
            'igUserId',
            $this->resolveIgUserId($chatwootConversation, $contactInbox, $chatwootInbox)
        );

        // Update denormalized links
        $conversation->set('chatwootContactId', $cwtContact->getId());
        $conversation->set('contactId', $cwtContact->get('contactId')); // Denormalized from ChatwootContact

        if ($contactInbox) {
            $conversation->set('contactInboxId', $contactInbox->getId());
        }

        if ($chatwootInbox) {
            $conversation->set('inboxId', $chatwootInbox->getId()); // Link to ChatwootInbox entity
        }

        // Assign teams from ChatwootAccount
        if ($teamId) {
            $conversation->set('teamsIds', [$teamId]);
        }

        $this->entityManager->saveEntity($conversation, ['silent' => true]);

        // Update WhatsApp chat labels if assignee changed
        if ($assigneeChanged && $chatwootInbox) {
            $this->updateChatLabelForConversation($conversation, $chatwootInbox, $newAssigneeId);
        }

        return 'synced';
    }

    /**
     * Create a new ChatwootConversation from Chatwoot data.
     *
     * @param string|null $teamId Team ID to assign to synced entities
     */
    private function createNewConversation(
        array $chatwootConversation,
        string $espoAccountId,
        Entity $cwtContact,
        ?Entity $contactInbox,
        ?Entity $chatwootInbox,
        ?string $teamId = null
    ): string {
        // Extract assignee info (can be null)
        $assignee = $chatwootConversation['meta']['assignee'] ?? null;
        $sender = $chatwootConversation['meta']['sender'] ?? null;
        $channel = $chatwootConversation['meta']['channel'] ?? null;

        // Generate display name
        $contactName = $sender['name'] ?? $cwtContact->get('name') ?? '';
        $name = $contactName ?: 'Conversation #' . $chatwootConversation['id'];

        // Get last message content and type from messages array
        $messages = $chatwootConversation['messages'] ?? [];
        $lastMessageContent = '';
        $lastMessageType = 'incoming';
        if (!empty($messages)) {
            $lastMessage = end($messages);
            $lastMessageContent = mb_substr(strip_tags($lastMessage['content'] ?? ''), 0, 200);
            // Map message_type: 0=incoming, 1=outgoing, 2=activity, 3=template
            $messageTypeMap = [0 => 'incoming', 1 => 'outgoing', 2 => 'activity', 3 => 'template'];
            $lastMessageType = $messageTypeMap[$lastMessage['message_type'] ?? 0] ?? 'incoming';
        }

        $data = [
            'name' => $name,
            'chatwootConversationId' => $chatwootConversation['id'],
            'chatwootAccountId' => $espoAccountId,
            'chatwootContactId' => $cwtContact->getId(),
            'contactId' => $cwtContact->get('contactId'), // Denormalized from ChatwootContact
            'contactInboxId' => $contactInbox?->getId(),
            'inboxId' => $chatwootInbox?->getId(), // Link to ChatwootInbox entity
            'status' => $chatwootConversation['status'] ?? 'open',
            'chatwootInboxId' => $chatwootConversation['inbox_id'] ?? null,
            'inboxName' => $channel,
            'assigneeId' => $assignee['id'] ?? null,
            'assigneeName' => $assignee['name'] ?? $assignee['available_name'] ?? null,
            'lastActivityAt' => $this->convertChatwootTimestamp($chatwootConversation['last_activity_at'] ?? null),
            'chatwootCreatedAt' => $this->convertChatwootTimestamp($chatwootConversation['created_at'] ?? null),
            'lastSyncedAt' => date('Y-m-d H:i:s'),
            // Denormalized fields for kanban card display
            'contactDisplayName' => $contactName,
            'contactAvatarUrl' => $cwtContact->get('avatarUrl'),
            'contactPhoneNumber' => $cwtContact->get('phoneNumber'),
            'lastMessageContent' => $lastMessageContent,
            'lastMessageType' => $lastMessageType,
            'inboxChannelType' => $chatwootInbox?->get('channelType'),
            'igUserId' => $this->resolveIgUserId($chatwootConversation, $contactInbox, $chatwootInbox),
        ];

        // Assign teams from ChatwootAccount
        if ($teamId) {
            $data['teamsIds'] = [$teamId];
        }

        $this->entityManager->createEntity('ChatwootConversation', $data, ['silent' => true]);

        return 'synced';
    }

    /**
     * Sync messages for a conversation.
     *
     * @param string|null $teamId Team ID to assign to synced entities
     */
    private function syncMessages(
        array $messages,
        Entity $conversation,
        Entity $cwtContact,
        string $espoAccountId,
        ?string $teamId = null
    ): void {
        foreach ($messages as $messageData) {
            $chatwootMessageId = $messageData['id'] ?? null;
            if (!$chatwootMessageId) {
                continue;
            }

            try {
                // Check if message already exists
                $existingMessage = $this->entityManager
                    ->getRDBRepository('ChatwootMessage')
                    ->where([
                        'chatwootMessageId' => $chatwootMessageId,
                        'chatwootAccountId' => $espoAccountId,
                    ])
                    ->findOne();

                // Map message_type: 0=incoming, 1=outgoing, 2=activity, 3=template
                $messageTypeMap = [
                    0 => 'incoming',
                    1 => 'outgoing',
                    2 => 'activity',
                    3 => 'template'
                ];
                $messageType = $messageTypeMap[$messageData['message_type'] ?? 0] ?? 'incoming';

                // Generate display name (truncated content)
                $content = $messageData['content'] ?? '';
                $name = mb_substr(strip_tags($content), 0, 100);
                if (mb_strlen($content) > 100) {
                    $name .= '...';
                }
                if (!$name) {
                    $name = 'Message #' . $chatwootMessageId;
                }

                // Get sender info
                $sender = $messageData['sender'] ?? null;
                $senderName = null;
                if ($sender) {
                    $senderName = $sender['name'] ?? $sender['available_name'] ?? null;
                }

                $data = [
                    'name' => $name,
                    'chatwootMessageId' => $chatwootMessageId,
                    'conversationId' => $conversation->getId(),
                    'chatwootContactId' => $cwtContact->getId(),
                    'contactId' => $cwtContact->get('contactId'), // denormalized
                    'chatwootAccountId' => $espoAccountId,
                    'content' => $content,
                    'messageType' => $messageType,
                    'contentType' => $messageData['content_type'] ?? 'text',
                    'status' => $messageData['status'] ?? 'sent',
                    'isPrivate' => $messageData['private'] ?? false,
                    'senderType' => $messageData['sender_type'] ?? null,
                    'senderId' => $messageData['sender_id'] ?? null,
                    'senderName' => $senderName,
                    'chatwootCreatedAt' => $this->convertChatwootTimestamp($messageData['created_at'] ?? null),
                    'chatwootUpdatedAt' => $this->convertChatwootTimestamp($messageData['updated_at'] ?? null),
                    'sourceId' => $messageData['source_id'] ?? null,
                    'lastSyncedAt' => date('Y-m-d H:i:s'),
                ];

                if ($existingMessage) {
                    foreach ($data as $field => $value) {
                        $existingMessage->set($field, $value);
                    }
                    // Assign teams from ChatwootAccount (messages use teams linkMultiple)
                    if ($teamId) {
                        $existingMessage->set('teamsIds', [$teamId]);
                    }
                    $this->entityManager->saveEntity($existingMessage, ['silent' => true]);
                } else {
                    // Assign teams from ChatwootAccount (messages use teams linkMultiple)
                    if ($teamId) {
                        $data['teamsIds'] = [$teamId];
                    }
                    $this->entityManager->createEntity('ChatwootMessage', $data);
                }
            } catch (\Exception $e) {
                $this->log->debug(
                    "SyncConversationsFromChatwoot: Failed to sync message {$chatwootMessageId}: " . $e->getMessage()
                );
            }
        }

        // Update the conversation's messagesCount and lastActivityAt from synced messages
        $messageRepo = $this->entityManager->getRDBRepository('ChatwootMessage');
        
        $messagesCount = $messageRepo
            ->where(['conversationId' => $conversation->getId()])
            ->count();

        // Get the most recent message to update lastActivityAt
        $lastMessage = $messageRepo
            ->where(['conversationId' => $conversation->getId()])
            ->order('chatwootCreatedAt', 'DESC')
            ->findOne();

        $conversation->set('messagesCount', $messagesCount);
        
        if ($lastMessage && $lastMessage->get('chatwootCreatedAt')) {
            $conversation->set('lastActivityAt', $lastMessage->get('chatwootCreatedAt'));
            // Also update last message content and type for kanban preview
            $conversation->set('lastMessageContent', mb_substr(strip_tags($lastMessage->get('content') ?? ''), 0, 200));
            $conversation->set('lastMessageType', $lastMessage->get('messageType'));
            
            // Auto-pending logic: toggle status based on last message direction
            $this->applyAutoPendingLogic($conversation, $espoAccountId);
        }

        // Update last outgoing (sent) message timestamp
        $lastOutgoing = $messageRepo
            ->where([
                'conversationId' => $conversation->getId(),
                'messageType' => 'outgoing',
            ])
            ->order('chatwootCreatedAt', 'DESC')
            ->findOne();

        if ($lastOutgoing && $lastOutgoing->get('chatwootCreatedAt')) {
            $conversation->set('lastMessageSentAt', $lastOutgoing->get('chatwootCreatedAt'));
        }

        // Update last incoming (received) message timestamp
        $lastIncoming = $messageRepo
            ->where([
                'conversationId' => $conversation->getId(),
                'messageType' => 'incoming',
            ])
            ->order('chatwootCreatedAt', 'DESC')
            ->findOne();

        if ($lastIncoming && $lastIncoming->get('chatwootCreatedAt')) {
            $conversation->set('lastMessageReceivedAt', $lastIncoming->get('chatwootCreatedAt'));
        }
        
        $this->entityManager->saveEntity($conversation, ['silent' => true]);
    }

    /**
     * Apply auto-pending logic to toggle conversation status based on last message direction.
     * 
     * Rules:
     * - If status is "open" and lastMessageType is "outgoing" → move to "pending"
     * - If status is "pending" and lastMessageType is "incoming" → move to "open"
     */
    private function applyAutoPendingLogic(Entity $conversation, string $espoAccountId): void
    {
        $currentStatus = $conversation->get('status');
        $lastMessageType = $conversation->get('lastMessageType');
        
        // Determine if we need to change status
        $newStatus = null;
        if ($currentStatus === 'open' && $lastMessageType === 'outgoing') {
            $newStatus = 'pending';
        } elseif ($currentStatus === 'pending' && $lastMessageType === 'incoming') {
            $newStatus = 'open';
        }
        
        if ($newStatus === null) {
            return;
        }
        
        // Get account and check if auto-pending is enabled
        $account = $this->entityManager->getEntityById('ChatwootAccount', $espoAccountId);
        if (!$account) {
            return;
        }
        
        // Check autoPendingEnabled at inbox integration level
        $inboxId = $conversation->get('inboxId');
        if (!$inboxId) {
            return;
        }
        
        $inbox = $this->entityManager->getEntityById('ChatwootInbox', $inboxId);
        if (!$inbox) {
            return;
        }
        
        $inboxIntegrationId = $inbox->get('chatwootInboxIntegrationId');
        if (!$inboxIntegrationId) {
            return;
        }
        
        $inboxIntegration = $this->entityManager->getEntityById('ChatwootInboxIntegration', $inboxIntegrationId);
        if (!$inboxIntegration) {
            return;
        }
        
        $autoPendingEnabled = $inboxIntegration->get('autoPendingEnabled') ?? false;
        if (!$autoPendingEnabled) {
            return;
        }
        
        $chatwootAccountId = $account->get('chatwootAccountId');
        $apiKey = $account->get('apiKey');
        $platformId = $account->get('platformId');
        
        if (!$chatwootAccountId || !$apiKey || !$platformId) {
            return;
        }
        
        $platform = $this->entityManager->getEntityById('ChatwootPlatform', $platformId);
        if (!$platform) {
            return;
        }
        
        $platformUrl = $platform->get('backendUrl');
        if (!$platformUrl) {
            return;
        }
        
        $chatwootConversationId = $conversation->get('chatwootConversationId');
        if (!$chatwootConversationId) {
            return;
        }
        
        try {
            // Toggle status in Chatwoot
            $this->apiClient->toggleConversationStatus(
                $platformUrl,
                $apiKey,
                $chatwootAccountId,
                $chatwootConversationId,
                $newStatus
            );
            
            // Update local status
            $conversation->set('status', $newStatus);
            
            $this->log->info(
                "SyncConversationsFromChatwoot: Auto-moved conversation {$chatwootConversationId} " .
                "from {$currentStatus} to {$newStatus} (lastMessageType: {$lastMessageType})"
            );
        } catch (\Exception $e) {
            $this->log->debug(
                "SyncConversationsFromChatwoot: Failed to auto-toggle conversation {$chatwootConversationId} " .
                "to {$newStatus}: " . $e->getMessage()
            );
        }
    }

    /**
     * Map Chatwoot message type integer to string.
     */
    private function mapMessageType(int $type): string
    {
        $map = [
            0 => 'incoming',
            1 => 'outgoing',
            2 => 'activity',
            3 => 'template'
        ];
        return $map[$type] ?? 'incoming';
    }

    /**
     * Convert Chatwoot timestamp to EspoCRM datetime string.
     * Handles both Unix timestamps (int) and ISO date strings.
     */
    private function convertChatwootTimestamp(int|string|null $timestamp): ?string
    {
        if ($timestamp === null) {
            return null;
        }

        // If it's already a string (ISO format), parse it
        if (is_string($timestamp)) {
            $parsed = strtotime($timestamp);
            if ($parsed === false) {
                return null;
            }
            return date('Y-m-d H:i:s', $parsed);
        }

        // Unix timestamp (int)
        return date('Y-m-d H:i:s', $timestamp);
    }

    /**
     * Get team IDs from a ChatwootAccount.
     *
     * @return array<string>
     */
    private function getAccountTeamsIds(Entity $account): array
    {
        return $account->getLinkMultipleIdList('teams');
    }

    /**
     * Find an entity by criteria, including soft-deleted records.
     * 
     * EspoCRM uses soft-deletion by default, so deleted records are marked with deleted=true
     * but still exist in the database. This can cause issues when syncing data that was
     * previously deleted.
     */
    private function findEntityIncludingDeleted(string $entityType, array $where): ?Entity
    {
        $query = $this->entityManager
            ->getQueryBuilder()
            ->select()
            ->from($entityType)
            ->where($where)
            ->withDeleted()
            ->build();

        return $this->entityManager
            ->getRDBRepository($entityType)
            ->clone($query)
            ->findOne();
    }

    private const RECONCILE_FAIL_THRESHOLD = 3;

    /**
     * Reconcile ChatwootConversations that may have been deleted in Chatwoot.
     * 
     * This runs after a full sync is complete (no more pages remaining).
     * It checks conversations that haven't been synced recently and verifies
     * they still exist in Chatwoot. If not found (HTTP 404), the conversation's
     * reconcileFailCount is incremented. Only after RECONCILE_FAIL_THRESHOLD
     * consecutive confirmed 404 responses will the conversation be removed
     * from EspoCRM. This prevents data loss from transient API errors.
     */
    private function reconcileDeletedConversations(
        string $platformUrl,
        string $apiKey,
        int $chatwootAccountId,
        string $espoAccountId
    ): void {
        $this->log->info("SyncConversationsFromChatwoot: Starting reconciliation for account {$espoAccountId}");

        $oneHourAgo = date('Y-m-d H:i:s', time() - 3600);
        
        $conversationsToCheck = $this->entityManager
            ->getRDBRepository('ChatwootConversation')
            ->where([
                'chatwootAccountId' => $espoAccountId,
                'lastSyncedAt<' => $oneHourAgo,
            ])
            ->limit(50)
            ->find();

        $checkedCount = 0;
        $deletedCount = 0;
        $markedCount = 0;

        foreach ($conversationsToCheck as $conversation) {
            $chatwootConversationId = $conversation->get('chatwootConversationId');
            
            if (!$chatwootConversationId) {
                continue;
            }
            
            $isConfirmed404 = false;

            try {
                $chatwootConversation = $this->apiClient->getConversation(
                    $platformUrl,
                    $apiKey,
                    $chatwootAccountId,
                    $chatwootConversationId
                );
                
                if ($chatwootConversation === null) {
                    $isConfirmed404 = true;
                } else {
                    // Conversation exists — reset fail counter and update sync timestamp
                    $conversation->set('lastSyncedAt', date('Y-m-d H:i:s'));
                    $conversation->set('reconcileFailCount', 0);
                    $this->entityManager->saveEntity($conversation, ['silent' => true]);
                }
                
            } catch (\Exception $e) {
                $msg = $e->getMessage();
                $httpCode = $this->extractHttpStatusCode($msg);

                if ($httpCode === 404) {
                    $isConfirmed404 = true;
                } else {
                    // Any non-404 error (500, timeout, class issues, etc.) — never treat as deletion
                    $this->log->warning(
                        "SyncConversationsFromChatwoot: Reconciliation skipped for conversation " .
                        "{$chatwootConversationId} due to non-404 error (HTTP {$httpCode}): {$msg}"
                    );
                }
            }

            if ($isConfirmed404) {
                $failCount = (int)$conversation->get('reconcileFailCount') + 1;
                
                if ($failCount >= self::RECONCILE_FAIL_THRESHOLD) {
                    $this->log->warning(
                        "SyncConversationsFromChatwoot: Conversation {$chatwootConversationId} " .
                        "confirmed gone from Chatwoot after {$failCount} consecutive checks, " .
                        "removing from EspoCRM (entity: {$conversation->getId()})"
                    );
                    $this->handleDeletedConversation($conversation);
                    $deletedCount++;
                } else {
                    $this->log->info(
                        "SyncConversationsFromChatwoot: Conversation {$chatwootConversationId} " .
                        "not found in Chatwoot (strike {$failCount}/" . self::RECONCILE_FAIL_THRESHOLD . "), " .
                        "will retry before deleting"
                    );
                    $conversation->set('reconcileFailCount', $failCount);
                    $this->entityManager->saveEntity($conversation, ['silent' => true]);
                    $markedCount++;
                }
            }
            
            $checkedCount++;
        }

        if ($checkedCount > 0) {
            $this->log->info(
                "SyncConversationsFromChatwoot: Reconciliation complete - " .
                "checked {$checkedCount}, deleted {$deletedCount}, marked {$markedCount}"
            );
        }
    }

    /**
     * Extract HTTP status code from an error message string.
     * Returns the status code (e.g. 404, 500) or 0 if not found.
     */
    private function extractHttpStatusCode(string $message): int
    {
        // Match patterns like "HTTP 404", "HTTP 500", "status 404", "code 404"
        if (preg_match('/\bHTTP\s+(\d{3})\b/i', $message, $matches)) {
            return (int)$matches[1];
        }
        if (preg_match('/\b(?:status|code)\s+(\d{3})\b/i', $message, $matches)) {
            return (int)$matches[1];
        }
        // Match standalone 3-digit HTTP codes that look like status codes
        if (preg_match('/\b(4\d{2}|5\d{2})\b/', $message, $matches)) {
            return (int)$matches[1];
        }
        return 0;
    }

    /**
     * Handle a ChatwootConversation that has been confirmed deleted from Chatwoot
     * after multiple consecutive 404 responses.
     * Removes the conversation and its associated messages from EspoCRM.
     */
    private function handleDeletedConversation(Entity $conversation): void
    {
        $conversationId = $conversation->getId();
        $chatwootConversationId = $conversation->get('chatwootConversationId');

        $this->log->warning(
            "SyncConversationsFromChatwoot: Removing conversation {$chatwootConversationId} " .
            "(entity {$conversationId}) from EspoCRM — confirmed deleted in Chatwoot"
        );

        // skipChatwootDelete tells the VerifyExistsInChatwoot hook to not call the
        // Chatwoot API since we already confirmed the conversation is gone there
        $this->entityManager->removeEntity($conversation, [
            'cascadeParent' => true,
            'skipChatwootDelete' => true,
        ]);
    }

    /**
     * Update WhatsApp chat labels when a conversation's assignee changes.
     */
    private function updateChatLabelForConversation(Entity $conversation, Entity $inbox, ?int $assigneeId): void
    {
        try {
            // Find ChatwootInboxIntegration
            $inboxIntegration = $this->findIntegrationForInbox($inbox);

            if (!$inboxIntegration) {
                $this->log->debug("SyncConversationsFromChatwoot: No ChatwootInboxIntegration found for inbox {$inbox->getId()}");
                return;
            }

            // Get WAHA platform and session info
            $wahaPlatformId = $inboxIntegration->get('wahaPlatformId');

            if (!$wahaPlatformId) {
                $this->log->debug("SyncConversationsFromChatwoot: No wahaPlatformId on integration {$inboxIntegration->getId()}");
                return;
            }

            $wahaPlatform = $this->entityManager->getEntityById(
                'WahaPlatform',
                $wahaPlatformId
            );

            if (!$wahaPlatform) {
                $this->log->debug("SyncConversationsFromChatwoot: WahaPlatform not found for integration {$inboxIntegration->getId()}");
                return;
            }

            $platformUrl = $wahaPlatform->get('backendUrl');
            $apiKey = $wahaPlatform->get('apiKey');
            $sessionName = $inboxIntegration->get('wahaSessionName');

            if (!$platformUrl || !$apiKey || !$sessionName) {
                $this->log->debug("SyncConversationsFromChatwoot: Missing WAHA credentials or session name");
                return;
            }

            // Build WhatsApp chatId from contactPhoneNumber
            $phoneNumber = $conversation->get('contactPhoneNumber');
            if (!$phoneNumber) {
                $this->log->debug("SyncConversationsFromChatwoot: Conversation has no contactPhoneNumber");
                return;
            }

            $chatId = $this->buildChatId($phoneNumber);
            if (!$chatId) {
                $this->log->debug("SyncConversationsFromChatwoot: Could not build chatId from phone number {$phoneNumber}");
                return;
            }

            // Determine which labels to set
            $labels = [];

            if ($assigneeId) {
                // Find membership by resolving through ChatwootUser (assigneeId is the platform user ID)
                $membership = $this->findMembershipByPlatformUserId($assigneeId, $conversation->get('chatwootAccountId'));

                if ($membership) {
                    // Find WahaSessionLabel for this membership + inboxIntegration
                    $wahaSessionLabel = $this->entityManager
                        ->getRDBRepository('WahaSessionLabel')
                        ->where([
                            'accountUserMembershipId' => $membership->getId(),
                            'inboxIntegrationId' => $inboxIntegration->getId(),
                        ])
                        ->findOne();

                    if ($wahaSessionLabel && $wahaSessionLabel->get('wahaLabelId')) {
                        // Verify label exists in WAHA, recreate if necessary
                        $validLabelId = $this->ensureLabelExists(
                            $platformUrl,
                            $apiKey,
                            $sessionName,
                            $wahaSessionLabel,
                            $membership
                        );

                        if ($validLabelId) {
                            $labels[] = ['id' => $validLabelId];
                            $this->log->info(
                                "SyncConversationsFromChatwoot: Setting label {$validLabelId} " .
                                "for chat {$chatId} (membership {$membership->get('name')})"
                            );
                        }
                    } else {
                        // No WahaSessionLabel exists - create one on-the-fly
                        $this->log->info(
                            "SyncConversationsFromChatwoot: No WahaSessionLabel found for membership {$membership->getId()} " .
                            "+ integration {$inboxIntegration->getId()}, creating..."
                        );

                        $newLabelId = $this->createLabelForMembership(
                            $platformUrl,
                            $apiKey,
                            $sessionName,
                            $membership,
                            $inboxIntegration
                        );

                        if ($newLabelId) {
                            $labels[] = ['id' => $newLabelId];
                            $this->log->info(
                                "SyncConversationsFromChatwoot: Created and setting label {$newLabelId} " .
                                "for chat {$chatId} (membership {$membership->get('name')})"
                            );
                        }
                    }
                } else {
                    $this->log->debug("SyncConversationsFromChatwoot: No membership found for platformUserId {$assigneeId}");
                }
            } else {
                // Unassigned - remove all agent labels
                $this->log->info("SyncConversationsFromChatwoot: Removing labels for chat {$chatId} (unassigned)");
            }

            // Call WAHA API to update chat labels
            $this->wahaApiClient->updateChatLabels(
                $platformUrl,
                $apiKey,
                $sessionName,
                $chatId,
                $labels
            );

            $this->log->info(
                "SyncConversationsFromChatwoot: Updated labels for chat {$chatId} - " .
                (count($labels) > 0 ? "set " . count($labels) . " label(s)" : "removed all labels")
            );

        } catch (\Exception $e) {
            $this->log->error(
                "SyncConversationsFromChatwoot: Failed to update labels for conversation {$conversation->getId()}: " .
                $e->getMessage()
            );
        }
    }

    /**
     * Create a new WAHA label for a membership and store the WahaSessionLabel record.
     *
     * @return string|null The new label ID, or null if creation failed
     */
    private function createLabelForMembership(
        string $platformUrl,
        string $apiKey,
        string $sessionName,
        Entity $membership,
        Entity $inboxIntegration
    ): ?string {
        try {
            // Generate label name based on membership type (AI or human)
            $labelPrefix = $membership->get('isAI') ? '[✨]' : '[👤]';
            $labelName = $labelPrefix . ' ' . $membership->get('name');
            $color = abs(crc32($membership->getId())) % 20;
            $colorHex = self::COLOR_MAP[$color] ?? '#64c4ff';

            // Create label in WAHA
            $wahaResponse = $this->wahaApiClient->createLabel(
                $platformUrl,
                $apiKey,
                $sessionName,
                [
                    'name' => $labelName,
                    'color' => $color,
                ]
            );

            $wahaLabelId = $wahaResponse['id'] ?? null;

            if (!$wahaLabelId) {
                $this->log->error("SyncConversationsFromChatwoot: WAHA response missing label ID when creating label for membership {$membership->getId()}");
                return null;
            }

            // Create WahaSessionLabel record
            $this->entityManager->createEntity('WahaSessionLabel', [
                'name' => $labelName,
                'wahaLabelId' => (string)$wahaLabelId,
                'color' => $color,
                'colorHex' => $wahaResponse['colorHex'] ?? $colorHex,
                'accountUserMembershipId' => $membership->getId(),
                'inboxIntegrationId' => $inboxIntegration->getId(),
                'teamsIds' => $inboxIntegration->getLinkMultipleIdList('teams'),
                'syncStatus' => 'synced',
            ], ['silent' => true]);

            $this->log->info(
                "SyncConversationsFromChatwoot: Created WahaSessionLabel for membership {$membership->getId()} with WAHA ID {$wahaLabelId}"
            );

            return (string)$wahaLabelId;

        } catch (\Exception $e) {
            $this->log->error(
                "SyncConversationsFromChatwoot: Failed to create label for membership {$membership->getId()}: " . $e->getMessage()
            );
            return null;
        }
    }

    /**
     * Ensure the label exists in WAHA. If not, recreate it and update the WahaSessionLabel record.
     * If session is not ready, falls back to using the stored label ID.
     *
     * @return string|null The valid label ID, or null if unable to ensure label exists
     */
    private function ensureLabelExists(
        string $platformUrl,
        string $apiKey,
        string $sessionName,
        Entity $wahaSessionLabel,
        Entity $membership
    ): ?string {
        $storedLabelId = $wahaSessionLabel->get('wahaLabelId');

        try {
            // Get all labels from WAHA to check if our label exists
            $wahaLabels = $this->wahaApiClient->listLabels($platformUrl, $apiKey, $sessionName);

            // Check if the label with our stored ID exists
            $labelExists = false;
            foreach ($wahaLabels as $label) {
                if ((string)($label['id'] ?? '') === (string)$storedLabelId) {
                    $labelExists = true;
                    break;
                }
            }

            if ($labelExists) {
                return $storedLabelId;
            }

            // Label doesn't exist in WAHA, need to recreate it
            $this->log->debug(
                "SyncConversationsFromChatwoot: Label {$storedLabelId} not found in WAHA session {$sessionName}, recreating..."
            );

            // Generate label name based on membership type (AI or human)
            $labelPrefix = $membership->get('isAI') ? '[✨]' : '[👤]';
            $labelName = $labelPrefix . ' ' . $membership->get('name');
            $color = abs(crc32($membership->getId())) % 20;

            // Create new label in WAHA
            $wahaResponse = $this->wahaApiClient->createLabel(
                $platformUrl,
                $apiKey,
                $sessionName,
                [
                    'name' => $labelName,
                    'color' => $color,
                ]
            );

            $newLabelId = $wahaResponse['id'] ?? null;

            if (!$newLabelId) {
                $this->log->error("SyncConversationsFromChatwoot: Failed to recreate label - WAHA response missing label ID");
                return null;
            }

            // Update the WahaSessionLabel record with the new ID
            $wahaSessionLabel->set('wahaLabelId', (string)$newLabelId);
            $wahaSessionLabel->set('name', $labelName);
            $wahaSessionLabel->set('color', $color);
            $wahaSessionLabel->set('colorHex', $wahaResponse['colorHex'] ?? self::COLOR_MAP[$color] ?? '#64c4ff');
            $this->entityManager->saveEntity($wahaSessionLabel, ['silent' => true]);

            $this->log->info(
                "SyncConversationsFromChatwoot: Recreated label '{$labelName}' with new ID {$newLabelId} (was {$storedLabelId})"
            );

            return (string)$newLabelId;

        } catch (\Exception $e) {
            // Check if this is a session not ready error (422)
            $message = $e->getMessage();
            if (strpos($message, '422') !== false || strpos($message, 'STARTING') !== false || strpos($message, 'not as expected') !== false) {
                $this->log->debug(
                    "SyncConversationsFromChatwoot: Session {$sessionName} not ready, using stored label ID {$storedLabelId} (best effort)"
                );
                // Return stored ID - the updateChatLabels call may still work or fail gracefully
                return $storedLabelId;
            }

            $this->log->error(
                "SyncConversationsFromChatwoot: Failed to verify/recreate label {$storedLabelId}: " . $message
            );
            return null;
        }
    }

    /**
     * Find ChatwootInboxIntegration for an inbox using multiple methods.
     */
    private function findIntegrationForInbox(Entity $inbox): ?Entity
    {
        // Method 1: Direct relationship
        $integrationId = $inbox->get('chatwootInboxIntegrationId');
        if ($integrationId) {
            $integration = $this->entityManager->getEntityById('ChatwootInboxIntegration', $integrationId);
            if ($integration) {
                return $integration;
            }
        }

        // Method 2: By inboxIdentifier
        $inboxIdentifier = $inbox->get('inboxIdentifier');
        if ($inboxIdentifier) {
            $integration = $this->entityManager
                ->getRDBRepository('ChatwootInboxIntegration')
                ->where(['chatwootInboxIdentifier' => $inboxIdentifier])
                ->findOne();

            if ($integration) {
                return $integration;
            }
        }

        // Method 3: By chatwootInboxId
        $chatwootInboxId = $inbox->get('chatwootInboxId');
        if ($chatwootInboxId) {
            $integration = $this->entityManager
                ->getRDBRepository('ChatwootInboxIntegration')
                ->where(['chatwootInboxId' => $chatwootInboxId])
                ->findOne();

            if ($integration) {
                return $integration;
            }
        }

        return null;
    }

    /**
     * Build a WhatsApp chatId from a phone number.
     * Format: {phoneNumber}@c.us (for individual chats)
     */
    private function buildChatId(string $phoneNumber): ?string
    {
        // Remove any non-numeric characters except leading +
        $cleaned = preg_replace('/[^0-9+]/', '', $phoneNumber);
        
        if (!$cleaned) {
            return null;
        }

        // Remove leading + if present
        $cleaned = ltrim($cleaned, '+');

        if (empty($cleaned)) {
            return null;
        }

        // WhatsApp chatId format for individual chats
        return $cleaned . '@c.us';
    }

    /**
     * Find a ChatwootAccountUserMembership by the Chatwoot platform user ID (assigneeId).
     * Resolves through ChatwootUser: platformUserId -> ChatwootUser -> Membership.
     */
    private function findMembershipByPlatformUserId(int $platformUserId, ?string $accountId): ?Entity
    {
        if (!$accountId) {
            return null;
        }

        // Find memberships linked to a ChatwootUser whose chatwootUserId matches
        $memberships = $this->entityManager
            ->getRDBRepository('ChatwootAccountUserMembership')
            ->leftJoin('chatwootUser')
            ->where([
                'chatwootUser.chatwootUserId' => $platformUserId,
                'chatwootAccountId' => $accountId,
            ])
            ->find();

        // Return the first match (should be at most one per account)
        foreach ($memberships as $membership) {
            return $membership;
        }

        return null;
    }
}


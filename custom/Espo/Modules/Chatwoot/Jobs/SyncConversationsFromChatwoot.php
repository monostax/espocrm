<?php

namespace Espo\Modules\Chatwoot\Jobs;

use Espo\Core\Job\JobDataLess;
use Espo\Core\Utils\Log;
use Espo\ORM\EntityManager;
use Espo\ORM\Entity;
use Espo\Modules\Chatwoot\Services\ChatwootApiClient;
use Espo\Modules\Chatwoot\Services\WahaApiClient;

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

            // Get team from the ChatwootAccount
            $teamId = $this->getAccountTeamId($account);

            // Sync conversations
            $result = $this->syncConversations(
                $platformUrl,
                $apiKey,
                $chatwootAccountId,
                $account->getId(),
                $cursor,
                $teamId
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
        ?string $teamId = null
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
                    $result = $this->syncSingleConversation($chatwootConversation, $espoAccountId, $teamId);

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
    private function syncSingleConversation(array $chatwootConversation, string $espoAccountId, ?string $teamId = null): string
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
            $cwtContact = $this->createContactFromSenderData($senderData, $espoAccountId, $teamId);
            
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

        return $result;
    }

    /**
     * Create a ChatwootContact from conversation sender data.
     * This handles contacts that don't appear in the contacts filter API (e.g., Instagram contacts).
     *
     * @param array $senderData Sender data from conversation meta
     * @param string $espoAccountId EspoCRM ChatwootAccount ID
     * @param string|null $teamId Team ID to assign
     * @return Entity|null The created ChatwootContact, or null on failure
     */
    private function createContactFromSenderData(array $senderData, string $espoAccountId, ?string $teamId = null): ?Entity
    {
        $chatwootContactId = (int) ($senderData['id'] ?? 0);
        if (!$chatwootContactId) {
            return null;
        }

        $teamsIds = $teamId ? [$teamId] : [];

        // Check again to prevent race conditions
        $existingCwtContact = $this->findEntityIncludingDeleted('ChatwootContact', [
            'chatwootContactId' => $chatwootContactId,
            'chatwootAccountId' => $espoAccountId,
        ]);

        if ($existingCwtContact) {
            // Restore if soft-deleted
            $this->entityManager
                ->getRDBRepository('ChatwootContact')
                ->restoreDeleted($existingCwtContact->getId());
            return $this->entityManager->getEntityById('ChatwootContact', $existingCwtContact->getId());
        }

        // Extract contact data from sender
        $name = $senderData['name'] ?? null;
        $phoneNumber = $senderData['phone_number'] ?? null;
        $email = $senderData['email'] ?? null;
        $avatarUrl = $senderData['thumbnail'] ?? null;
        $identifier = $senderData['identifier'] ?? null;
        $blocked = $senderData['blocked'] ?? false;
        $lastActivityAt = $senderData['last_activity_at'] ?? null;
        $createdAt = $senderData['created_at'] ?? null;

        // Try to find existing EspoCRM Contact by phone or email within the same team
        $espoContact = null;
        if ($phoneNumber) {
            $espoContact = $this->findContactByFieldInTeams('phoneNumber', $phoneNumber, $teamsIds);
        }
        if (!$espoContact && $email) {
            $espoContact = $this->findContactByFieldInTeams('emailAddress', $email, $teamsIds);
        }

        // Create ChatwootContact bridge record
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

            // Explicitly set teams after creation (linkMultiple requires explicit save)
            if (!empty($teamsIds)) {
                $cwtContact->set('teamsIds', $teamsIds);
                $this->entityManager->saveEntity($cwtContact, ['silent' => true]);
            }

            // Auto-create EspoCRM Contact if not found and we have enough data
            if (!$espoContact && $name && ($phoneNumber || $email)) {
                $espoContact = $this->createEspoContactFromSenderData($senderData, $teamsIds);
                if ($espoContact) {
                    $cwtContact->set('contactId', $espoContact->getId());
                    $this->entityManager->saveEntity($cwtContact, ['silent' => true]);
                }
            }

            $this->log->info("SyncConversationsFromChatwoot: Created ChatwootContact {$cwtContact->getId()} for Chatwoot contact {$chatwootContactId} with teams " . json_encode($teamsIds));
            return $cwtContact;

        } catch (\Exception $e) {
            $this->log->error("SyncConversationsFromChatwoot: Failed to create ChatwootContact for {$chatwootContactId}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Find an EspoCRM Contact by a field value, scoped to teams.
     */
    private function findContactByFieldInTeams(string $field, string $value, array $teamsIds): ?Entity
    {
        $queryBuilder = $this->entityManager
            ->getQueryBuilder()
            ->select()
            ->from('Contact')
            ->where([$field => $value])
            ->withDeleted();

        if (!empty($teamsIds)) {
            $queryBuilder->join('teams', 'teams');
            $queryBuilder->where(['teams.id' => $teamsIds]);
        }

        $query = $queryBuilder->build();
        $contact = $this->entityManager
            ->getRDBRepository('Contact')
            ->clone($query)
            ->findOne();

        if ($contact) {
            $this->entityManager
                ->getRDBRepository('Contact')
                ->restoreDeleted($contact->getId());
            return $this->entityManager->getEntityById('Contact', $contact->getId());
        }

        return null;
    }

    /**
     * Create an EspoCRM Contact from sender data.
     */
    private function createEspoContactFromSenderData(array $senderData, array $teamsIds): ?Entity
    {
        $name = $senderData['name'] ?? 'Unknown';
        $nameParts = explode(' ', $name, 2);

        $data = [
            'firstName' => $nameParts[0] ?? '',
            'lastName' => $nameParts[1] ?? '',
            'phoneNumber' => $senderData['phone_number'] ?? null,
            'emailAddress' => $senderData['email'] ?? null,
            'description' => 'Imported from Chatwoot conversation',
        ];

        if (!empty($teamsIds)) {
            $data['teamsIds'] = $teamsIds;
        }

        try {
            $contact = $this->entityManager->createEntity('Contact', $data, ['silent' => true]);
            
            // Explicitly set teams after creation (linkMultiple requires explicit save)
            if (!empty($teamsIds)) {
                $contact->set('teamsIds', $teamsIds);
                $this->entityManager->saveEntity($contact, ['silent' => true]);
            }
            
            return $contact;
        } catch (\Exception $e) {
            $this->log->debug("SyncConversationsFromChatwoot: Failed to create EspoCRM Contact: " . $e->getMessage());
            return null;
        }
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

        // Generate display name with date
        $contactName = $sender['name'] ?? $cwtContact->get('name') ?? '';
        $createdAt = $chatwootConversation['created_at'] ?? null;
        $dateStr = $createdAt ? date('Y-m-d', $createdAt) : '';
        
        $nameParts = [];
        if ($dateStr) {
            $nameParts[] = $dateStr;
        }
        if ($contactName) {
            $nameParts[] = $contactName;
        }
        $name = implode(' - ', $nameParts);
        if (!$name) {
            $name = 'Conversation #' . $chatwootConversation['id'];
        }

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

        // Update denormalized links
        $conversation->set('chatwootContactId', $cwtContact->getId());
        $conversation->set('contactId', $cwtContact->get('contactId')); // Denormalized from ChatwootContact

        if ($contactInbox) {
            $conversation->set('contactInboxId', $contactInbox->getId());
        }

        if ($chatwootInbox) {
            $conversation->set('inboxId', $chatwootInbox->getId()); // Link to ChatwootInbox entity
        }

        // Assign team from ChatwootAccount
        if ($teamId) {
            $conversation->set('teamId', $teamId);
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

        // Generate display name with date
        $contactName = $sender['name'] ?? $cwtContact->get('name') ?? '';
        $createdAt = $chatwootConversation['created_at'] ?? null;
        $dateStr = $createdAt ? date('Y-m-d', $createdAt) : '';
        
        $nameParts = [];
        if ($dateStr) {
            $nameParts[] = $dateStr;
        }
        if ($contactName) {
            $nameParts[] = $contactName;
        }
        $name = implode(' - ', $nameParts);
        if (!$name) {
            $name = 'Conversation #' . $chatwootConversation['id'];
        }

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
        ];

        // Assign team from ChatwootAccount
        if ($teamId) {
            $data['teamId'] = $teamId;
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
                    $this->entityManager->createEntity('ChatwootMessage', $data, ['silent' => true]);
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
        
        $autoPendingEnabled = $account->get('autoPendingEnabled') ?? true;
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
     * Get team ID from a ChatwootAccount.
     *
     * @return string|null
     */
    private function getAccountTeamId(Entity $account): ?string
    {
        return $account->get('teamId');
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

    /**
     * Reconcile ChatwootConversations that may have been deleted in Chatwoot.
     * 
     * This runs after a full sync is complete (no more pages remaining).
     * It checks conversations that haven't been synced recently and verifies
     * they still exist in Chatwoot. If not found (404), the conversation
     * and its messages are soft-deleted from EspoCRM.
     */
    private function reconcileDeletedConversations(
        string $platformUrl,
        string $apiKey,
        int $chatwootAccountId,
        string $espoAccountId
    ): void {
        $this->log->info("SyncConversationsFromChatwoot: Starting reconciliation for account {$espoAccountId}");

        // Get ChatwootConversations that haven't been synced recently
        // Only check conversations that were last synced more than 1 hour ago to avoid
        // checking conversations we just synced
        $oneHourAgo = date('Y-m-d H:i:s', time() - 3600);
        
        $conversationsToCheck = $this->entityManager
            ->getRDBRepository('ChatwootConversation')
            ->where([
                'chatwootAccountId' => $espoAccountId,
                'lastSyncedAt<' => $oneHourAgo,
            ])
            ->limit(50) // Check in batches to avoid timeout
            ->find();

        $checkedCount = 0;
        $deletedCount = 0;

        foreach ($conversationsToCheck as $conversation) {
            $chatwootConversationId = $conversation->get('chatwootConversationId');
            
            if (!$chatwootConversationId) {
                continue;
            }
            
            try {
                // Try to get the conversation from Chatwoot
                $chatwootConversation = $this->apiClient->getConversation(
                    $platformUrl,
                    $apiKey,
                    $chatwootAccountId,
                    $chatwootConversationId
                );
                
                if ($chatwootConversation === null) {
                    // 404 - conversation doesn't exist in Chatwoot anymore
                    $this->handleDeletedConversation($conversation);
                    $deletedCount++;
                } else {
                    // Conversation exists, update lastSyncedAt to prevent re-checking
                    $conversation->set('lastSyncedAt', date('Y-m-d H:i:s'));
                    $this->entityManager->saveEntity($conversation, ['silent' => true]);
                }
                
            } catch (\Exception $e) {
                // Check if it's a 404 (conversation not found = deleted)
                if (strpos($e->getMessage(), '404') !== false || 
                    strpos($e->getMessage(), 'not found') !== false) {
                    
                    $this->handleDeletedConversation($conversation);
                    $deletedCount++;
                } else {
                    // Other error (API issue, etc) - log but don't mark as deleted
                    $this->log->debug(
                        "SyncConversationsFromChatwoot: Error checking conversation {$chatwootConversationId}: " . 
                        $e->getMessage()
                    );
                }
            }
            
            $checkedCount++;
        }

        if ($checkedCount > 0) {
            $this->log->info(
                "SyncConversationsFromChatwoot: Reconciliation complete - " .
                "checked {$checkedCount}, deleted {$deletedCount}"
            );
        }
    }

    /**
     * Handle a ChatwootConversation that no longer exists in Chatwoot.
     * Soft-deletes the conversation and all associated messages.
     */
    private function handleDeletedConversation(Entity $conversation): void
    {
        $conversationId = $conversation->getId();
        $chatwootConversationId = $conversation->get('chatwootConversationId');

        $this->log->info(
            "SyncConversationsFromChatwoot: Conversation {$chatwootConversationId} " .
            "no longer exists in Chatwoot, deleting from EspoCRM"
        );

        // Delete the conversation - cascade delete will handle messages automatically
        // Using cascadeParent to skip remote API calls since entity already deleted on Chatwoot
        $this->entityManager->removeEntity($conversation, ['cascadeParent' => true]);
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
            $wahaPlatform = $this->entityManager->getEntityById(
                'WahaPlatform',
                $inboxIntegration->get('wahaPlatformId')
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
                // Find ChatwootAgent by chatwootAgentId
                $agent = $this->entityManager
                    ->getRDBRepository('ChatwootAgent')
                    ->where([
                        'chatwootAgentId' => $assigneeId,
                        'chatwootAccountId' => $conversation->get('chatwootAccountId'),
                    ])
                    ->findOne();

                if ($agent) {
                    // Find WahaSessionLabel for this agent + inboxIntegration
                    $wahaSessionLabel = $this->entityManager
                        ->getRDBRepository('WahaSessionLabel')
                        ->where([
                            'agentId' => $agent->getId(),
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
                            $agent
                        );

                        if ($validLabelId) {
                            $labels[] = ['id' => $validLabelId];
                            $this->log->info(
                                "SyncConversationsFromChatwoot: Setting label {$validLabelId} " .
                                "for chat {$chatId} (agent {$agent->get('name')})"
                            );
                        }
                    } else {
                        // No WahaSessionLabel exists - create one on-the-fly
                        $this->log->info(
                            "SyncConversationsFromChatwoot: No WahaSessionLabel found for agent {$agent->getId()} " .
                            "+ integration {$inboxIntegration->getId()}, creating..."
                        );

                        $newLabelId = $this->createLabelForAgent(
                            $platformUrl,
                            $apiKey,
                            $sessionName,
                            $agent,
                            $inboxIntegration
                        );

                        if ($newLabelId) {
                            $labels[] = ['id' => $newLabelId];
                            $this->log->info(
                                "SyncConversationsFromChatwoot: Created and setting label {$newLabelId} " .
                                "for chat {$chatId} (agent {$agent->get('name')})"
                            );
                        }
                    }
                } else {
                    $this->log->debug("SyncConversationsFromChatwoot: ChatwootAgent with chatwootAgentId {$assigneeId} not found");
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
     * Create a new WAHA label for an agent and store the WahaSessionLabel record.
     *
     * @return string|null The new label ID, or null if creation failed
     */
    private function createLabelForAgent(
        string $platformUrl,
        string $apiKey,
        string $sessionName,
        Entity $agent,
        Entity $inboxIntegration
    ): ?string {
        try {
            // Generate label name based on agent type (AI or human)
            $labelPrefix = $agent->get('isAI') ? '[✨]' : '[👤]';
            $labelName = $labelPrefix . ' ' . $agent->get('name');
            $color = abs(crc32($agent->getId())) % 20;
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
                $this->log->error("SyncConversationsFromChatwoot: WAHA response missing label ID when creating label for agent {$agent->getId()}");
                return null;
            }

            // Create WahaSessionLabel record
            $this->entityManager->createEntity('WahaSessionLabel', [
                'name' => $labelName,
                'wahaLabelId' => (string)$wahaLabelId,
                'color' => $color,
                'colorHex' => $wahaResponse['colorHex'] ?? $colorHex,
                'agentId' => $agent->getId(),
                'inboxIntegrationId' => $inboxIntegration->getId(),
                'teamId' => $inboxIntegration->get('teamId'),
                'syncStatus' => 'synced',
            ], ['silent' => true]);

            $this->log->info(
                "SyncConversationsFromChatwoot: Created WahaSessionLabel for agent {$agent->getId()} with WAHA ID {$wahaLabelId}"
            );

            return (string)$wahaLabelId;

        } catch (\Exception $e) {
            $this->log->error(
                "SyncConversationsFromChatwoot: Failed to create label for agent {$agent->getId()}: " . $e->getMessage()
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
        Entity $agent
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

            // Generate label name based on agent type (AI or human)
            $labelPrefix = $agent->get('isAI') ? '[✨]' : '[👤]';
            $labelName = $labelPrefix . ' ' . $agent->get('name');
            $color = abs(crc32($agent->getId())) % 20;

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
}


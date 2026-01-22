<?php

namespace Espo\Modules\Chatwoot\Jobs;

use Espo\Core\Job\JobDataLess;
use Espo\Core\Utils\Log;
use Espo\ORM\EntityManager;
use Espo\ORM\Entity;
use Espo\Modules\Chatwoot\Services\ChatwootApiClient;

/**
 * Scheduled job to sync conversations from Chatwoot to EspoCRM.
 * Iterates through all ChatwootAccount records with contactSyncEnabled = true
 * and pulls conversations from Chatwoot.
 */
class SyncConversationsFromChatwoot implements JobDataLess
{
    private const MAX_PAGES_PER_RUN = 50;
    private const PAGE_SIZE = 25; // Chatwoot conversations API page size

    public function __construct(
        private EntityManager $entityManager,
        private ChatwootApiClient $apiClient,
        private Log $log
    ) {}

    public function run(): void
    {
        $this->log->warning('SyncConversationsFromChatwoot: Job started');

        try {
            $accounts = $this->getEnabledAccounts();
            $accountList = iterator_to_array($accounts);
            $accountCount = count($accountList);

            $this->log->warning("SyncConversationsFromChatwoot: Found {$accountCount} account(s) to sync");

            foreach ($accountList as $account) {
                $this->syncAccountConversations($account);
            }

            $this->log->warning("SyncConversationsFromChatwoot: Job completed - processed {$accountCount} account(s)");
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
            $teamsIds = $teamId ? [$teamId] : [];

            // Sync conversations
            $result = $this->syncConversations(
                $platformUrl,
                $apiKey,
                $chatwootAccountId,
                $account->getId(),
                $cursor,
                $teamsIds
            );

            // Update sync timestamps and cursor
            $account->set('lastConversationSyncAt', date('Y-m-d H:i:s'));
            if ($result['newCursor'] !== null) {
                $account->set('conversationSyncCursor', $result['newCursor']);
            }
            $this->entityManager->saveEntity($account, ['silent' => true]);

            $this->log->warning(
                "SyncConversationsFromChatwoot: Account {$accountName} - " .
                "{$result['synced']} synced, {$result['skipped']} skipped, {$result['errors']} errors" .
                ($result['hasMore'] ? " (more pages remaining)" : " (complete)")
            );

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
     * @param array<string> $teamsIds Team IDs to assign to synced entities
     * @return array{synced: int, skipped: int, errors: int, newCursor: int|null, hasMore: bool}
     */
    private function syncConversations(
        string $platformUrl,
        string $apiKey,
        int $chatwootAccountId,
        string $espoAccountId,
        ?int $cursor = null,
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

        $this->log->warning(
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

            $this->log->warning(
                "SyncConversationsFromChatwoot: Page {$page} - " . count($conversations) .
                " conversations, total: {$allCount}"
            );

            foreach ($conversations as $chatwootConversation) {
                try {
                    $result = $this->syncSingleConversation($chatwootConversation, $espoAccountId, $teamsIds);

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
                    $this->log->warning(
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
     * @param array<string> $teamsIds Team IDs to assign to synced entities
     * @return string 'synced' or 'skipped'
     */
    private function syncSingleConversation(array $chatwootConversation, string $espoAccountId, array $teamsIds = []): string
    {
        $chatwootConversationId = (int) $chatwootConversation['id'];
        $inboxId = isset($chatwootConversation['inbox_id']) ? (int) $chatwootConversation['inbox_id'] : null;
        $contactId = isset($chatwootConversation['meta']['sender']['id']) ? (int) $chatwootConversation['meta']['sender']['id'] : null;

        if (!$inboxId || !$contactId) {
            $this->log->warning("SyncConversationsFromChatwoot: Skipping conversation {$chatwootConversationId} - missing inboxId or contactId");
            return 'skipped';
        }

        // Find the ChatwootContact for this conversation (including soft-deleted records)
        $cwtContact = $this->findEntityIncludingDeleted('ChatwootContact', [
            'chatwootContactId' => $contactId,
            'chatwootAccountId' => $espoAccountId,
        ]);

        if (!$cwtContact) {
            // Contact hasn't been synced yet, skip this conversation
            $this->log->warning("SyncConversationsFromChatwoot: Skipping conversation {$chatwootConversationId} - ChatwootContact not found for Chatwoot contact ID {$contactId}");
            return 'skipped';
        }

        // Restore soft-deleted contact using the proper EspoCRM method
        $this->entityManager
            ->getRDBRepository('ChatwootContact')
            ->restoreDeleted($cwtContact->getId());
        
        // Re-fetch the entity after restoration
        $cwtContact = $this->entityManager->getEntityById('ChatwootContact', $cwtContact->getId());

        // Find the ChatwootContactInbox for this conversation
        $contactInbox = $this->entityManager
            ->getRDBRepository('ChatwootContactInbox')
            ->where([
                'chatwootContactId' => $cwtContact->getId(),
                'chatwootInboxId' => $inboxId,
                'chatwootAccountId' => $espoAccountId,
            ])
            ->findOne();

        // Find the ChatwootInbox entity for linking
        $chatwootInbox = $this->entityManager
            ->getRDBRepository('ChatwootInbox')
            ->where([
                'chatwootInboxId' => $inboxId,
                'chatwootAccountId' => $espoAccountId,
            ])
            ->findOne();

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
            
            $result = $this->updateExistingConversation($existingConversation, $chatwootConversation, $cwtContact, $contactInbox, $chatwootInbox, $teamsIds);
            $conversation = $existingConversation;
        } else {
            $result = $this->createNewConversation($chatwootConversation, $espoAccountId, $cwtContact, $contactInbox, $chatwootInbox, $teamsIds);
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
                $this->syncMessages($messages, $conversation, $cwtContact, $espoAccountId, $teamsIds);
            }
        }

        return $result;
    }

    /**
     * Update an existing ChatwootConversation from Chatwoot data.
     *
     * @param array<string> $teamsIds Team IDs to assign to synced entities
     */
    private function updateExistingConversation(
        Entity $conversation,
        array $chatwootConversation,
        Entity $cwtContact,
        ?Entity $contactInbox,
        ?Entity $chatwootInbox,
        array $teamsIds = []
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

        $conversation->set('name', $name);
        $conversation->set('status', $chatwootConversation['status'] ?? 'open');
        $conversation->set('chatwootInboxId', $chatwootConversation['inbox_id'] ?? null);
        $conversation->set('inboxName', $channel);
        $conversation->set('assigneeId', $assignee['id'] ?? null);
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

        // Assign teams from ChatwootAccount
        if (!empty($teamsIds)) {
            $conversation->set('teamsIds', $teamsIds);
        }

        $this->entityManager->saveEntity($conversation, ['silent' => true]);

        return 'synced';
    }

    /**
     * Create a new ChatwootConversation from Chatwoot data.
     *
     * @param array<string> $teamsIds Team IDs to assign to synced entities
     */
    private function createNewConversation(
        array $chatwootConversation,
        string $espoAccountId,
        Entity $cwtContact,
        ?Entity $contactInbox,
        ?Entity $chatwootInbox,
        array $teamsIds = []
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

        // Assign teams from ChatwootAccount
        if (!empty($teamsIds)) {
            $data['teamsIds'] = $teamsIds;
        }

        $this->entityManager->createEntity('ChatwootConversation', $data, ['silent' => true]);

        return 'synced';
    }

    /**
     * Sync messages for a conversation.
     *
     * @param array<string> $teamsIds Team IDs to assign to synced entities
     */
    private function syncMessages(
        array $messages,
        Entity $conversation,
        Entity $cwtContact,
        string $espoAccountId,
        array $teamsIds = []
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
                    // Assign teams from ChatwootAccount
                    if (!empty($teamsIds)) {
                        $existingMessage->set('teamsIds', $teamsIds);
                    }
                    $this->entityManager->saveEntity($existingMessage, ['silent' => true]);
                } else {
                    // Assign teams from ChatwootAccount
                    if (!empty($teamsIds)) {
                        $data['teamsIds'] = $teamsIds;
                    }
                    $this->entityManager->createEntity('ChatwootMessage', $data, ['silent' => true]);
                }
            } catch (\Exception $e) {
                $this->log->warning(
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
            $this->log->warning(
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
}


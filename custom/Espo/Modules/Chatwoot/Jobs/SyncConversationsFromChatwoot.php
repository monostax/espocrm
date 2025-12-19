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

            $platformUrl = $platform->get('url');
            $apiKey = $account->get('apiKey');
            $chatwootAccountId = $account->get('chatwootAccountId');

            if (!$platformUrl || !$apiKey || !$chatwootAccountId) {
                throw new \Exception('Missing platform URL, API key, or Chatwoot account ID');
            }

            // Get cursor for incremental sync
            $cursor = $account->get('conversationSyncCursor');

            // Sync conversations
            $result = $this->syncConversations(
                $platformUrl,
                $apiKey,
                $chatwootAccountId,
                $account->getId(),
                $cursor
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
     * @return array{synced: int, skipped: int, errors: int, newCursor: int|null, hasMore: bool}
     */
    private function syncConversations(
        string $platformUrl,
        string $apiKey,
        int $chatwootAccountId,
        string $espoAccountId,
        ?int $cursor = null
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
                    $result = $this->syncSingleConversation($chatwootConversation, $espoAccountId);

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
     * @return string 'synced' or 'skipped'
     */
    private function syncSingleConversation(array $chatwootConversation, string $espoAccountId): string
    {
        $chatwootConversationId = $chatwootConversation['id'];
        $inboxId = $chatwootConversation['inbox_id'] ?? null;
        $contactId = $chatwootConversation['meta']['sender']['id'] ?? null;

        if (!$inboxId || !$contactId) {
            $this->log->warning("SyncConversationsFromChatwoot: Skipping conversation {$chatwootConversationId} - missing inboxId or contactId");
            return 'skipped';
        }

        // Find the ChatwootContact for this conversation
        $cwtContact = $this->entityManager
            ->getRDBRepository('ChatwootContact')
            ->where([
                'chatwootContactId' => $contactId,
                'chatwootAccountId' => $espoAccountId,
            ])
            ->findOne();

        if (!$cwtContact) {
            // Contact hasn't been synced yet, skip this conversation
            $this->log->warning("SyncConversationsFromChatwoot: Skipping conversation {$chatwootConversationId} - ChatwootContact not found for Chatwoot contact ID {$contactId}");
            return 'skipped';
        }

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

        // Check if ChatwootConversation already exists
        $existingConversation = $this->entityManager
            ->getRDBRepository('ChatwootConversation')
            ->where([
                'chatwootConversationId' => $chatwootConversationId,
                'chatwootAccountId' => $espoAccountId,
            ])
            ->findOne();

        $conversation = null;
        $result = 'skipped';

        if ($existingConversation) {
            $result = $this->updateExistingConversation($existingConversation, $chatwootConversation, $cwtContact, $contactInbox, $chatwootInbox);
            $conversation = $existingConversation;
        } else {
            $result = $this->createNewConversation($chatwootConversation, $espoAccountId, $cwtContact, $contactInbox, $chatwootInbox);
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
                $this->syncMessages($messages, $conversation, $cwtContact, $espoAccountId);
            }
        }

        return $result;
    }

    /**
     * Update an existing ChatwootConversation from Chatwoot data.
     */
    private function updateExistingConversation(
        Entity $conversation,
        array $chatwootConversation,
        Entity $cwtContact,
        ?Entity $contactInbox,
        ?Entity $chatwootInbox
    ): string {
        // Extract assignee info (can be null)
        $assignee = $chatwootConversation['meta']['assignee'] ?? null;
        $sender = $chatwootConversation['meta']['sender'] ?? null;
        $channel = $chatwootConversation['meta']['channel'] ?? null;
        
        // Count messages from the messages array
        $messagesCount = isset($chatwootConversation['messages']) 
            ? count($chatwootConversation['messages']) 
            : 0;

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
        if ($channel) {
            $name .= ' (' . $channel . ')';
        }
        if (!$name) {
            $name = 'Conversation #' . $chatwootConversation['id'];
        }

        $conversation->set('name', $name);
        $conversation->set('status', $chatwootConversation['status'] ?? 'open');
        $conversation->set('chatwootInboxId', $chatwootConversation['inbox_id'] ?? null);
        $conversation->set('inboxName', $channel);
        $conversation->set('assigneeId', $assignee['id'] ?? null);
        $conversation->set('assigneeName', $assignee['name'] ?? $assignee['available_name'] ?? null);
        $conversation->set('messagesCount', $messagesCount);
        $conversation->set('lastActivityAt', $this->convertChatwootTimestamp($chatwootConversation['last_activity_at'] ?? null));
        $conversation->set('lastSyncedAt', date('Y-m-d H:i:s'));

        // Update denormalized links
        $conversation->set('chatwootContactId', $cwtContact->getId());
        $conversation->set('contactId', $cwtContact->get('contactId')); // Denormalized from ChatwootContact

        if ($contactInbox) {
            $conversation->set('contactInboxId', $contactInbox->getId());
        }

        if ($chatwootInbox) {
            $conversation->set('inboxId', $chatwootInbox->getId()); // Link to ChatwootInbox entity
        }

        $this->entityManager->saveEntity($conversation, ['silent' => true]);

        return 'synced';
    }

    /**
     * Create a new ChatwootConversation from Chatwoot data.
     */
    private function createNewConversation(
        array $chatwootConversation,
        string $espoAccountId,
        Entity $cwtContact,
        ?Entity $contactInbox,
        ?Entity $chatwootInbox
    ): string {
        // Extract assignee info (can be null)
        $assignee = $chatwootConversation['meta']['assignee'] ?? null;
        $sender = $chatwootConversation['meta']['sender'] ?? null;
        $channel = $chatwootConversation['meta']['channel'] ?? null;
        
        // Count messages from the messages array
        $messagesCount = isset($chatwootConversation['messages']) 
            ? count($chatwootConversation['messages']) 
            : 0;

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
        if ($channel) {
            $name .= ' (' . $channel . ')';
        }
        if (!$name) {
            $name = 'Conversation #' . $chatwootConversation['id'];
        }

        $this->entityManager->createEntity('ChatwootConversation', [
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
            'messagesCount' => $messagesCount,
            'lastActivityAt' => $this->convertChatwootTimestamp($chatwootConversation['last_activity_at'] ?? null),
            'chatwootCreatedAt' => $this->convertChatwootTimestamp($chatwootConversation['created_at'] ?? null),
            'lastSyncedAt' => date('Y-m-d H:i:s'),
        ], ['silent' => true]);

        return 'synced';
    }

    /**
     * Sync messages for a conversation.
     */
    private function syncMessages(
        array $messages,
        Entity $conversation,
        Entity $cwtContact,
        string $espoAccountId
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
                    $this->entityManager->saveEntity($existingMessage, ['silent' => true]);
                } else {
                    $this->entityManager->createEntity('ChatwootMessage', $data, ['silent' => true]);
                }
            } catch (\Exception $e) {
                $this->log->warning(
                    "SyncConversationsFromChatwoot: Failed to sync message {$chatwootMessageId}: " . $e->getMessage()
                );
            }
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
}


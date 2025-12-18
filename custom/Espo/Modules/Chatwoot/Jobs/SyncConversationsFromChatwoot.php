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
    private const MAX_PAGES_PER_RUN = 10;

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

            // Sync conversations
            $stats = $this->syncConversations(
                $platformUrl,
                $apiKey,
                $chatwootAccountId,
                $account->getId()
            );

            // Update last sync timestamp
            $account->set('lastConversationSyncAt', date('Y-m-d H:i:s'));
            $this->entityManager->saveEntity($account, ['silent' => true]);

            $this->log->warning(
                "SyncConversationsFromChatwoot: Account {$accountName} - " .
                "{$stats['synced']} synced, {$stats['skipped']} skipped, {$stats['errors']} errors"
            );

        } catch (\Exception $e) {
            $this->log->error(
                "Chatwoot conversation sync failed for account {$accountName}: " . $e->getMessage()
            );
        }
    }

    /**
     * Sync conversations from Chatwoot to EspoCRM.
     *
     * @return array{synced: int, skipped: int, errors: int}
     */
    private function syncConversations(
        string $platformUrl,
        string $apiKey,
        int $chatwootAccountId,
        string $espoAccountId
    ): array {
        $stats = ['synced' => 0, 'skipped' => 0, 'errors' => 0];
        $page = 1;
        $pagesProcessed = 0;

        do {
            $response = $this->apiClient->listConversations(
                $platformUrl,
                $apiKey,
                $chatwootAccountId,
                $page,
                'all', // status
                'all'  // assignee_type
            );

            $conversations = $response['data']['payload'] ?? [];
            $meta = $response['data']['meta'] ?? [];

            // Get counts for pagination
            $allCount = $meta['all_count'] ?? count($conversations);
            
            $this->log->warning(
                "SyncConversationsFromChatwoot: Page {$page} - Found " . count($conversations) . 
                " conversations, all_count: {$allCount}"
            );

            foreach ($conversations as $chatwootConversation) {
                try {
                    $result = $this->syncSingleConversation($chatwootConversation, $espoAccountId);

                    if ($result === 'synced') {
                        $stats['synced']++;
                    } else {
                        $stats['skipped']++;
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

            // Chatwoot API typically returns 25 conversations per page
            $pageSize = 25;
            $totalPages = (int) ceil($allCount / $pageSize);
            $hasMorePages = $page <= $totalPages;

            // Stop if we've processed enough pages this run (prevent timeout)
            if ($pagesProcessed >= self::MAX_PAGES_PER_RUN) {
                break;
            }

        } while ($hasMorePages && count($conversations) > 0);

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
                'inboxId' => $inboxId,
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

        if ($existingConversation) {
            return $this->updateExistingConversation($existingConversation, $chatwootConversation, $cwtContact, $contactInbox);
        }

        return $this->createNewConversation($chatwootConversation, $espoAccountId, $cwtContact, $contactInbox);
    }

    /**
     * Update an existing ChatwootConversation from Chatwoot data.
     */
    private function updateExistingConversation(
        Entity $conversation,
        array $chatwootConversation,
        Entity $cwtContact,
        ?Entity $contactInbox
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
        $conversation->set('inboxId', $chatwootConversation['inbox_id'] ?? null);
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
        ?Entity $contactInbox
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
            'status' => $chatwootConversation['status'] ?? 'open',
            'inboxId' => $chatwootConversation['inbox_id'] ?? null,
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
     * Convert Chatwoot Unix timestamp to EspoCRM datetime string.
     */
    private function convertChatwootTimestamp(?int $timestamp): ?string
    {
        if ($timestamp === null) {
            return null;
        }
        return date('Y-m-d H:i:s', $timestamp);
    }
}

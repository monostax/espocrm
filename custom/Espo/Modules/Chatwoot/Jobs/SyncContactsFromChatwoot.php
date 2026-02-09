<?php

namespace Espo\Modules\Chatwoot\Jobs;

use Espo\Core\Job\JobDataLess;
use Espo\Core\Utils\Log;
use Espo\ORM\EntityManager;
use Espo\ORM\Entity;
use Espo\Modules\Chatwoot\Services\ChatwootApiClient;

/**
 * Scheduled job to sync contacts from Chatwoot to EspoCRM.
 * Iterates through all ChatwootAccount records with contactSyncEnabled = true
 * and pulls contacts from Chatwoot.
 */
class SyncContactsFromChatwoot implements JobDataLess
{
    private const MAX_PAGES_PER_RUN = 50;
    private const PAGE_SIZE = 15; // Fixed by Chatwoot API

    public function __construct(
        private EntityManager $entityManager,
        private ChatwootApiClient $apiClient,
        private Log $log
    ) {}

    public function run(): void
    {
        // Use WARNING level to ensure it's always logged
        $this->log->debug('SyncContactsFromChatwoot: Job started');

        try {
            $accounts = $this->getEnabledAccounts();
            $accountList = iterator_to_array($accounts);
            $accountCount = count($accountList);

            $this->log->debug("SyncContactsFromChatwoot: Found {$accountCount} account(s) to sync");

            foreach ($accountList as $account) {
                $this->syncAccountContacts($account);
            }

            $this->log->debug("SyncContactsFromChatwoot: Job completed - processed {$accountCount} account(s)");
        } catch (\Throwable $e) {
            $this->log->error('SyncContactsFromChatwoot: Job failed - ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
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
     * Sync contacts for a single ChatwootAccount.
     */
    private function syncAccountContacts(Entity $account): void
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
            $cursor = $account->get('contactSyncCursor');

            // Get teams from the ChatwootAccount
            $teamsIds = $this->getAccountTeamsIds($account);

            // Sync contacts
            $result = $this->syncContacts(
                $platformUrl,
                $apiKey,
                $chatwootAccountId,
                $account->getId(),
                $cursor,
                $teamsIds
            );

            // Update sync timestamps and cursor
            $account->set('lastContactSyncAt', date('Y-m-d H:i:s'));
            if ($result['newCursor'] !== null) {
                $account->set('contactSyncCursor', $result['newCursor']);
            }
            $this->entityManager->saveEntity($account, ['silent' => true]);

            $this->log->debug(
                "SyncContactsFromChatwoot: Account {$accountName} - " .
                "{$result['synced']} synced, {$result['skipped']} skipped, {$result['errors']} errors" .
                ($result['hasMore'] ? " (more pages remaining)" : " (complete)")
            );

            // Run reconciliation when sync is complete (no more pages)
            // This detects contacts that were deleted/merged in Chatwoot
            if (!$result['hasMore']) {
                $this->reconcileDeletedContacts(
                    $platformUrl,
                    $apiKey,
                    $chatwootAccountId,
                    $account->getId()
                );
            }

        } catch (\Exception $e) {
            $this->log->error(
                "Chatwoot contact sync failed for account {$accountName}: " . $e->getMessage()
            );
        }
    }

    /**
     * Sync contacts from Chatwoot to EspoCRM using cursor-based incremental sync.
     *
     * @param string $platformUrl
     * @param string $apiKey
     * @param int $chatwootAccountId
     * @param string $espoAccountId
     * @param int|null $cursor Unix timestamp of last synced contact's last_activity_at
     * @param array<string> $teamsIds Team IDs to assign to synced entities
     * @return array{synced: int, skipped: int, errors: int, newCursor: int|null, hasMore: bool}
     */
    private function syncContacts(
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

        $this->log->debug(
            "SyncContactsFromChatwoot: Starting sync with cursor=" .
            ($cursor !== null ? date('Y-m-d H:i:s', $cursor) . " ({$cursor})" : 'null')
        );

        do {
            $response = $this->apiClient->filterContacts(
                $platformUrl,
                $apiKey,
                $chatwootAccountId,
                $page,
                $filters
            );

            $contacts = $response['payload'] ?? [];
            $totalCount = $response['meta']['count'] ?? 0;
            $currentPage = (int) ($response['meta']['current_page'] ?? $page);

            $this->log->debug(
                "SyncContactsFromChatwoot: Page {$page} - " . count($contacts) .
                " contacts, total: {$totalCount}"
            );

            foreach ($contacts as $chatwootContact) {
                try {
                    $result = $this->syncSingleContact($chatwootContact, $espoAccountId, $teamsIds);

                    if ($result === 'synced') {
                        $stats['synced']++;
                    } else {
                        $stats['skipped']++;
                    }

                    // Track max last_activity_at for cursor update
                    $contactLastActivity = $chatwootContact['last_activity_at'] ?? null;
                    if ($contactLastActivity !== null) {
                        if ($maxLastActivityAt === null || $contactLastActivity > $maxLastActivityAt) {
                            $maxLastActivityAt = $contactLastActivity;
                        }
                    }
                } catch (\Exception $e) {
                    $stats['errors']++;
                    $this->log->debug(
                        "Failed to sync Chatwoot contact {$chatwootContact['id']}: " . $e->getMessage()
                    );
                }
            }

            $page++;
            $pagesProcessed++;

            $totalPages = (int) ceil($totalCount / self::PAGE_SIZE);
            $hasMorePages = $currentPage < $totalPages;

            // Stop if we've processed enough pages this run (prevent timeout)
            if ($pagesProcessed >= self::MAX_PAGES_PER_RUN) {
                $stats['hasMore'] = $hasMorePages;
                break;
            }

        } while ($hasMorePages && count($contacts) > 0);

        // Update cursor to max last_activity_at seen
        $stats['newCursor'] = $maxLastActivityAt;

        return $stats;
    }

    /**
     * Sync a single contact from Chatwoot to EspoCRM.
     *
     * @param array<string> $teamsIds Team IDs to assign to synced entities
     * @return string 'synced' or 'skipped'
     */
    private function syncSingleContact(array $chatwootContact, string $espoAccountId, array $teamsIds = []): string
    {
        // Ensure chatwootContactId is cast to int to avoid type mismatch in queries
        $chatwootContactId = (int) $chatwootContact['id'];
        $contactInboxes = $chatwootContact['contact_inboxes'] ?? [];

        // Check if ChatwootContact already exists (including soft-deleted records)
        // EspoCRM uses soft-deletion, so we need to check with withDeleted() to find
        // records that were deleted but still exist in the database
        $existingCwtContact = $this->findChatwootContactIncludingDeleted($chatwootContactId, $espoAccountId);

        if ($existingCwtContact) {
            // Restore soft-deleted records using the proper EspoCRM method
            // Setting deleted=false via set() doesn't persist - must use restoreDeleted()
            $this->entityManager
                ->getRDBRepository('ChatwootContact')
                ->restoreDeleted($existingCwtContact->getId());
            
            // Re-fetch the entity after restoration to ensure we have fresh state
            $existingCwtContact = $this->entityManager->getEntityById('ChatwootContact', $existingCwtContact->getId());
            
            $result = $this->updateExistingContact($existingCwtContact, $chatwootContact, $teamsIds);
            $this->syncContactInboxes($existingCwtContact, $contactInboxes, $espoAccountId, $teamsIds);
            return $result;
        }

        $cwtContact = $this->createNewContact($chatwootContact, $espoAccountId, $teamsIds);
        $this->syncContactInboxes($cwtContact, $contactInboxes, $espoAccountId, $teamsIds);
        return 'synced';
    }

    /**
     * Find a ChatwootContact by chatwootContactId and account, including soft-deleted records.
     * 
     * EspoCRM uses soft-deletion by default, so deleted records are marked with deleted=true
     * but still exist in the database. This can cause unique constraint violations when
     * trying to create a "new" record that was previously deleted.
     */
    private function findChatwootContactIncludingDeleted(int $chatwootContactId, string $espoAccountId): ?Entity
    {
        $query = $this->entityManager
            ->getQueryBuilder()
            ->select()
            ->from('ChatwootContact')
            ->where([
                'chatwootContactId' => $chatwootContactId,
                'chatwootAccountId' => $espoAccountId,
            ])
            ->withDeleted()
            ->build();

        return $this->entityManager
            ->getRDBRepository('ChatwootContact')
            ->clone($query)
            ->findOne();
    }

    /**
     * Update an existing ChatwootContact from Chatwoot data.
     *
     * @param array<string> $teamsIds Team IDs to assign to synced entities
     */
    private function updateExistingContact(Entity $cwtContact, array $chatwootContact, array $teamsIds = []): string
    {
        // Update the ChatwootContact record with latest Chatwoot data
        $cwtContact->set('name', $chatwootContact['name'] ?? null);
        $cwtContact->set('phoneNumber', $chatwootContact['phone_number'] ?? null);
        $cwtContact->set('email', $chatwootContact['email'] ?? null);
        $cwtContact->set('identifier', $chatwootContact['identifier'] ?? null);
        $cwtContact->set('availabilityStatus', $chatwootContact['availability_status'] ?? null);
        $cwtContact->set('blocked', $chatwootContact['blocked'] ?? false);
        $cwtContact->set('avatarUrl', $chatwootContact['thumbnail'] ?? null);
        $cwtContact->set('chatwootLastActivityAt', $this->convertChatwootTimestamp($chatwootContact['last_activity_at'] ?? null));
        $cwtContact->set('chatwootCreatedAt', $this->convertChatwootTimestamp($chatwootContact['created_at'] ?? null));
        $cwtContact->set('syncStatus', 'synced');
        $cwtContact->set('lastSyncedAt', date('Y-m-d H:i:s'));

        // Assign teams from ChatwootAccount
        if (!empty($teamsIds)) {
            $cwtContact->set('teamsIds', $teamsIds);
        }

        $this->entityManager->saveEntity($cwtContact, ['silent' => true]);

        // Update linked EspoCRM Contact if exists
        $contactId = $cwtContact->get('contactId');
        if ($contactId) {
            $this->updateEspoContact($contactId, $chatwootContact, $teamsIds);
        }

        return 'synced';
    }

    /**
     * Create a new ChatwootContact and optionally link/create EspoCRM Contact.
     *
     * @param array<string> $teamsIds Team IDs to assign to synced entities
     */
    private function createNewContact(array $chatwootContact, string $espoAccountId, array $teamsIds = []): Entity
    {
        // Try to find existing EspoCRM Contact by phone or email WITHIN THE SAME TEAMS (multi-tenant isolation)
        $espoContact = $this->findMatchingEspoContact($chatwootContact, $teamsIds);

        // Create ChatwootContact bridge record
        $data = [
            'chatwootContactId' => $chatwootContact['id'],
            'chatwootAccountId' => $espoAccountId,
            'contactId' => $espoContact?->getId(),
            'name' => $chatwootContact['name'] ?? null,
            'phoneNumber' => $chatwootContact['phone_number'] ?? null,
            'email' => $chatwootContact['email'] ?? null,
            'identifier' => $chatwootContact['identifier'] ?? null,
            'availabilityStatus' => $chatwootContact['availability_status'] ?? null,
            'blocked' => $chatwootContact['blocked'] ?? false,
            'avatarUrl' => $chatwootContact['thumbnail'] ?? null,
            'chatwootLastActivityAt' => $this->convertChatwootTimestamp($chatwootContact['last_activity_at'] ?? null),
            'chatwootCreatedAt' => $this->convertChatwootTimestamp($chatwootContact['created_at'] ?? null),
            'syncStatus' => 'synced',
            'lastSyncedAt' => date('Y-m-d H:i:s'),
        ];

        // Assign teams from ChatwootAccount
        if (!empty($teamsIds)) {
            $data['teamsIds'] = $teamsIds;
        }

        $cwtContact = $this->entityManager->createEntity('ChatwootContact', $data, ['silent' => true]);

        // Auto-create EspoCRM Contact if not found and we have enough data
        if (!$espoContact && $this->shouldAutoCreateEspoContact($chatwootContact)) {
            $espoContact = $this->createEspoContact($chatwootContact, $teamsIds);
            
            $cwtContact->set('contactId', $espoContact->getId());
            $this->entityManager->saveEntity($cwtContact, ['silent' => true]);
        }

        return $cwtContact;
    }

    /**
     * Find matching EspoCRM Contact by phone number or email within the same teams.
     * Includes soft-deleted contacts and restores them if found.
     * 
     * Teams are used for multi-tenant isolation - contacts from different tenants
     * should not be matched even if they have the same phone/email.
     *
     * @param array<string> $teamsIds Team IDs to scope the search (multi-tenant isolation)
     */
    private function findMatchingEspoContact(array $chatwootContact, array $teamsIds = []): ?Entity
    {
        $phoneNumber = $chatwootContact['phone_number'] ?? null;
        $email = $chatwootContact['email'] ?? null;

        if ($phoneNumber) {
            $contact = $this->findContactIncludingDeleted(['phoneNumber' => $phoneNumber], $teamsIds);
            if ($contact) {
                return $contact;
            }
        }

        if ($email) {
            $contact = $this->findContactIncludingDeleted(['emailAddress' => $email], $teamsIds);
            if ($contact) {
                return $contact;
            }
        }

        return null;
    }

    /**
     * Find a Contact including soft-deleted records, scoped to specific teams.
     * Restores the contact if found and soft-deleted.
     * 
     * @param array<string> $teamsIds Team IDs to scope the search (multi-tenant isolation)
     */
    private function findContactIncludingDeleted(array $where, array $teamsIds = []): ?Entity
    {
        $queryBuilder = $this->entityManager
            ->getQueryBuilder()
            ->select()
            ->from('Contact')
            ->where($where)
            ->withDeleted();

        // Scope to specific teams if provided (multi-tenant isolation)
        // Only match Contacts that belong to the same team(s) as the ChatwootAccount
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
            // Restore if soft-deleted
            $this->entityManager
                ->getRDBRepository('Contact')
                ->restoreDeleted($contact->getId());
            
            // Re-fetch to get fresh state
            return $this->entityManager->getEntityById('Contact', $contact->getId());
        }

        return null;
    }

    /**
     * Update EspoCRM Contact from Chatwoot data (only fills empty fields).
     * Also restores soft-deleted contacts.
     *
     * @param array<string> $teamsIds Team IDs to assign to synced entities
     */
    private function updateEspoContact(string $contactId, array $chatwootContact, array $teamsIds = []): void
    {
        // First, restore the contact if it was soft-deleted
        $this->entityManager
            ->getRDBRepository('Contact')
            ->restoreDeleted($contactId);
        
        // Now fetch the contact (should exist now)
        $contact = $this->entityManager->getEntityById('Contact', $contactId);

        if (!$contact) {
            return;
        }

        $name = $chatwootContact['name'] ?? '';
        $nameParts = explode(' ', $name, 2);

        // Only fill empty fields (conservative approach)
        if (!$contact->get('firstName') && isset($nameParts[0])) {
            $contact->set('firstName', $nameParts[0]);
        }
        if (!$contact->get('lastName') && isset($nameParts[1])) {
            $contact->set('lastName', $nameParts[1]);
        }
        if (!$contact->get('phoneNumber') && isset($chatwootContact['phone_number'])) {
            $contact->set('phoneNumber', $chatwootContact['phone_number']);
        }
        if (!$contact->get('emailAddress') && isset($chatwootContact['email'])) {
            $contact->set('emailAddress', $chatwootContact['email']);
        }

        // Assign teams from ChatwootAccount
        if (!empty($teamsIds)) {
            $contact->set('teamsIds', $teamsIds);
        }

        $this->entityManager->saveEntity($contact, ['silent' => true]);
    }

    /**
     * Determine if we should auto-create an EspoCRM Contact.
     */
    private function shouldAutoCreateEspoContact(array $chatwootContact): bool
    {
        $hasName = !empty($chatwootContact['name']);
        $hasPhone = !empty($chatwootContact['phone_number']);
        $hasEmail = !empty($chatwootContact['email']);

        return $hasName && ($hasPhone || $hasEmail);
    }

    /**
     * Create a new EspoCRM Contact from Chatwoot data.
     *
     * @param array<string> $teamsIds Team IDs to assign to synced entities
     */
    private function createEspoContact(array $chatwootContact, array $teamsIds = []): Entity
    {
        $name = $chatwootContact['name'] ?? 'Unknown';
        $nameParts = explode(' ', $name, 2);

        $data = [
            'firstName' => $nameParts[0] ?? '',
            'lastName' => $nameParts[1] ?? '',
            'phoneNumber' => $chatwootContact['phone_number'] ?? null,
            'emailAddress' => $chatwootContact['email'] ?? null,
            'description' => 'Imported from Chatwoot',
        ];

        // Assign teams from ChatwootAccount
        if (!empty($teamsIds)) {
            $data['teamsIds'] = $teamsIds;
        }

        return $this->entityManager->createEntity('Contact', $data, ['silent' => true]);
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

    /**
     * Sync contact inboxes from Chatwoot data.
     *
     * @param Entity $cwtContact The ChatwootContact entity
     * @param array $contactInboxes Array from Chatwoot API: [{source_id, inbox: {id, name, channel_type}}]
     * @param string $espoAccountId The EspoCRM ChatwootAccount ID
     * @param array<string> $teamsIds Team IDs to assign to synced entities
     */
    private function syncContactInboxes(Entity $cwtContact, array $contactInboxes, string $espoAccountId, array $teamsIds = []): void
    {
        foreach ($contactInboxes as $inboxData) {
            $inboxId = $inboxData['inbox']['id'] ?? null;
            if (!$inboxId) {
                continue;
            }

            $sourceId = $inboxData['source_id'] ?? null;
            $inboxName = $inboxData['inbox']['name'] ?? null;
            $channelType = $inboxData['inbox']['channel_type'] ?? null;
            $mappedChannelType = $this->mapChannelType($channelType);
            
            // Generate display name: "{contact} <> {inbox} ({channel type})"
            $contactName = $cwtContact->get('name') ?? 'Unknown';
            $inboxDisplayName = $inboxName ?? 'Inbox #' . $inboxId;
            if ($mappedChannelType) {
                $inboxDisplayName .= ' (' . $mappedChannelType . ')';
            }
            $name = $contactName . ' <> ' . $inboxDisplayName;

            // Find ChatwootInbox entity for linking
            $chatwootInbox = $this->entityManager
                ->getRDBRepository('ChatwootInbox')
                ->where([
                    'chatwootInboxId' => $inboxId,
                    'chatwootAccountId' => $espoAccountId,
                ])
                ->findOne();

            // Check if ChatwootContactInbox already exists
            $existing = $this->entityManager
                ->getRDBRepository('ChatwootContactInbox')
                ->where([
                    'chatwootContactId' => $cwtContact->getId(),
                    'chatwootInboxId' => $inboxId,
                    'chatwootAccountId' => $espoAccountId,
                ])
                ->findOne();

            if ($existing) {
                // Update existing
                $existing->set('name', $name);
                $existing->set('sourceId', $sourceId);
                $existing->set('inboxName', $inboxName);
                $existing->set('inboxChannelType', $mappedChannelType);
                $existing->set('contactId', $cwtContact->get('contactId')); // Denormalized
                $existing->set('inboxId', $chatwootInbox?->getId()); // Link to ChatwootInbox entity
                $existing->set('lastSyncedAt', date('Y-m-d H:i:s'));
                
                // Assign teams from ChatwootAccount
                if (!empty($teamsIds)) {
                    $existing->set('teamsIds', $teamsIds);
                }
                
                $this->entityManager->saveEntity($existing, ['silent' => true]);
            } else {
                // Create new
                $data = [
                    'name' => $name,
                    'chatwootContactId' => $cwtContact->getId(),
                    'contactId' => $cwtContact->get('contactId'), // Denormalized
                    'chatwootAccountId' => $espoAccountId,
                    'chatwootInboxId' => $inboxId, // Chatwoot inbox ID (int)
                    'inboxId' => $chatwootInbox?->getId(), // Link to ChatwootInbox entity
                    'inboxName' => $inboxName,
                    'inboxChannelType' => $mappedChannelType,
                    'sourceId' => $sourceId,
                    'lastSyncedAt' => date('Y-m-d H:i:s'),
                ];

                // Assign teams from ChatwootAccount
                if (!empty($teamsIds)) {
                    $data['teamsIds'] = $teamsIds;
                }

                $this->entityManager->createEntity('ChatwootContactInbox', $data, ['silent' => true]);
            }
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
     * Reconcile ChatwootContacts that may have been deleted or merged in Chatwoot.
     * 
     * Since Chatwoot HARD DELETES contacts on merge (with no merge tracking),
     * we can only detect that a contact no longer exists. We mark it as 'deleted'
     * since we cannot distinguish between a delete and a merge from Chatwoot's side.
     * 
     * This runs after a full sync is complete (no more pages remaining).
     */
    private function reconcileDeletedContacts(
        string $platformUrl,
        string $apiKey,
        int $chatwootAccountId,
        string $espoAccountId
    ): void {
        $this->log->info("SyncContactsFromChatwoot: Starting reconciliation for account {$espoAccountId}");

        // Get ChatwootContacts that are synced but haven't been checked recently
        // Only check contacts that were last synced more than 1 hour ago to avoid
        // checking contacts we just synced
        $oneHourAgo = date('Y-m-d H:i:s', time() - 3600);
        
        $contactsToCheck = $this->entityManager
            ->getRDBRepository('ChatwootContact')
            ->where([
                'chatwootAccountId' => $espoAccountId,
                'syncStatus' => 'synced',
                'lastSyncedAt<' => $oneHourAgo,
            ])
            ->limit(50) // Check in batches to avoid timeout
            ->find();

        $checkedCount = 0;
        $deletedCount = 0;

        foreach ($contactsToCheck as $cwtContact) {
            $chatwootContactId = $cwtContact->get('chatwootContactId');
            
            try {
                // Try to get the contact from Chatwoot
                $this->apiClient->getContact(
                    $platformUrl,
                    $apiKey,
                    $chatwootAccountId,
                    $chatwootContactId
                );
                
                // Contact exists, update lastSyncedAt to prevent re-checking
                $cwtContact->set('lastSyncedAt', date('Y-m-d H:i:s'));
                $this->entityManager->saveEntity($cwtContact, ['silent' => true]);
                
            } catch (\Exception $e) {
                // Check if it's a 404 (contact not found = deleted or merged)
                if (strpos($e->getMessage(), '404') !== false || 
                    strpos($e->getMessage(), 'not found') !== false) {
                    
                    $this->handleDeletedChatwootContact($cwtContact, $espoAccountId);
                    $deletedCount++;
                } else {
                    // Other error (API issue, etc) - log but don't mark as deleted
                    $this->log->debug(
                        "SyncContactsFromChatwoot: Error checking contact {$chatwootContactId}: " . 
                        $e->getMessage()
                    );
                }
            }
            
            $checkedCount++;
        }

        if ($checkedCount > 0) {
            $this->log->info(
                "SyncContactsFromChatwoot: Reconciliation complete - " .
                "checked {$checkedCount}, found {$deletedCount} deleted/merged"
            );
        }
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
     * Handle a ChatwootContact that no longer exists in Chatwoot.
     * 
     * Since Chatwoot doesn't provide merge tracking, we can only mark it as 'deleted'.
     * We attempt to find a potential merge target by looking for another ChatwootContact
     * with the same phone number or email that was recently updated.
     */
    private function handleDeletedChatwootContact(Entity $cwtContact, string $espoAccountId): void
    {
        $chatwootContactId = $cwtContact->get('chatwootContactId');
        $phoneNumber = $cwtContact->get('phoneNumber');
        $email = $cwtContact->get('email');

        $this->log->info(
            "SyncContactsFromChatwoot: Contact {$chatwootContactId} no longer exists in Chatwoot"
        );

        // Try to find a potential merge target (another ChatwootContact with same phone/email)
        $potentialMergeTarget = null;
        
        if ($phoneNumber) {
            $potentialMergeTarget = $this->entityManager
                ->getRDBRepository('ChatwootContact')
                ->where([
                    'chatwootAccountId' => $espoAccountId,
                    'phoneNumber' => $phoneNumber,
                    'syncStatus' => 'synced',
                    'id!=' => $cwtContact->getId(),
                ])
                ->findOne();
        }
        
        if (!$potentialMergeTarget && $email) {
            $potentialMergeTarget = $this->entityManager
                ->getRDBRepository('ChatwootContact')
                ->where([
                    'chatwootAccountId' => $espoAccountId,
                    'email' => $email,
                    'syncStatus' => 'synced',
                    'id!=' => $cwtContact->getId(),
                ])
                ->findOne();
        }

        if ($potentialMergeTarget) {
            // Found a potential merge target - this was likely a merge in Chatwoot
            $cwtContact->set('syncStatus', 'merged');
            $cwtContact->set('mergedIntoChatwootContactId', $potentialMergeTarget->get('chatwootContactId'));
            
            $this->log->info(
                "SyncContactsFromChatwoot: Contact {$chatwootContactId} appears to have been " .
                "merged into {$potentialMergeTarget->get('chatwootContactId')}"
            );
            
            // If both ChatwootContacts point to different EspoCRM Contacts,
            // we should consider merging them in EspoCRM too
            $deletedContactId = $cwtContact->get('contactId');
            $survivingContactId = $potentialMergeTarget->get('contactId');
            
            if ($deletedContactId && $survivingContactId && $deletedContactId !== $survivingContactId) {
                // Re-link the deleted ChatwootContact to the surviving EspoCRM Contact
                // This triggers the Contact afterSave hook which may trigger EspoCRM merge
                $cwtContact->set('contactId', $survivingContactId);
                
                $this->log->debug(
                    "SyncContactsFromChatwoot: ChatwootContact {$chatwootContactId} was linked to " .
                    "Contact {$deletedContactId}, now re-linked to {$survivingContactId}. " .
                    "Consider merging these EspoCRM Contacts manually."
                );
            }
        } else {
            // No merge target found - just mark as deleted
            $cwtContact->set('syncStatus', 'deleted');
            
            $this->log->info(
                "SyncContactsFromChatwoot: Contact {$chatwootContactId} marked as deleted"
            );
        }

        $this->entityManager->saveEntity($cwtContact, ['silent' => true]);
    }
}

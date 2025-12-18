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
    private const MAX_PAGES_PER_RUN = 10;

    public function __construct(
        private EntityManager $entityManager,
        private ChatwootApiClient $apiClient,
        private Log $log
    ) {}

    public function run(): void
    {
        // Use WARNING level to ensure it's always logged
        $this->log->warning('SyncContactsFromChatwoot: Job started');

        try {
            $accounts = $this->getEnabledAccounts();
            $accountList = iterator_to_array($accounts);
            $accountCount = count($accountList);

            $this->log->warning("SyncContactsFromChatwoot: Found {$accountCount} account(s) to sync");

            foreach ($accountList as $account) {
                $this->syncAccountContacts($account);
            }

            $this->log->warning("SyncContactsFromChatwoot: Job completed - processed {$accountCount} account(s)");
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

            $platformUrl = $platform->get('url');
            $apiKey = $account->get('apiKey');
            $chatwootAccountId = $account->get('chatwootAccountId');

            if (!$platformUrl || !$apiKey || !$chatwootAccountId) {
                throw new \Exception('Missing platform URL, API key, or Chatwoot account ID');
            }

            // Sync contacts
            $stats = $this->syncContacts(
                $platformUrl,
                $apiKey,
                $chatwootAccountId,
                $account->getId()
            );

            // Update last sync timestamp
            $account->set('lastContactSyncAt', date('Y-m-d H:i:s'));
            $this->entityManager->saveEntity($account, ['silent' => true]);

            $this->log->info(
                "Chatwoot contact sync completed for account {$accountName}: " .
                "{$stats['synced']} synced, {$stats['skipped']} skipped, {$stats['errors']} errors"
            );

        } catch (\Exception $e) {
            $this->log->error(
                "Chatwoot contact sync failed for account {$accountName}: " . $e->getMessage()
            );
        }
    }

    /**
     * Sync contacts from Chatwoot to EspoCRM.
     *
     * @return array{synced: int, skipped: int, errors: int}
     */
    private function syncContacts(
        string $platformUrl,
        string $apiKey,
        int $chatwootAccountId,
        string $espoAccountId
    ): array {
        $stats = ['synced' => 0, 'skipped' => 0, 'errors' => 0];
        $page = 1;
        $pagesProcessed = 0;
        $pageSize = 15; // Fixed by Chatwoot API

        do {
            $response = $this->apiClient->listContacts(
                $platformUrl,
                $apiKey,
                $chatwootAccountId,
                $page,
                '-last_activity_at'
            );

            $contacts = $response['payload'] ?? [];
            $totalCount = $response['meta']['count'] ?? 0;
            $currentPage = (int) ($response['meta']['current_page'] ?? $page);

            foreach ($contacts as $chatwootContact) {
                try {
                    $result = $this->syncSingleContact($chatwootContact, $espoAccountId);

                    if ($result === 'synced') {
                        $stats['synced']++;
                    } else {
                        $stats['skipped']++;
                    }
                } catch (\Exception $e) {
                    $stats['errors']++;
                    $this->log->warning(
                        "Failed to sync Chatwoot contact {$chatwootContact['id']}: " . $e->getMessage()
                    );
                }
            }

            $page++;
            $pagesProcessed++;

            $totalPages = (int) ceil($totalCount / $pageSize);
            $hasMorePages = $currentPage < $totalPages;

            // Stop if we've processed enough pages this run (prevent timeout)
            if ($pagesProcessed >= self::MAX_PAGES_PER_RUN) {
                break;
            }

        } while ($hasMorePages && count($contacts) > 0);

        return $stats;
    }

    /**
     * Sync a single contact from Chatwoot to EspoCRM.
     *
     * @return string 'synced' or 'skipped'
     */
    private function syncSingleContact(array $chatwootContact, string $espoAccountId): string
    {
        $chatwootContactId = $chatwootContact['id'];
        $contactInboxes = $chatwootContact['contact_inboxes'] ?? [];

        // Check if ChatwootContact already exists
        $existingCwtContact = $this->entityManager
            ->getRDBRepository('ChatwootContact')
            ->where([
                'chatwootContactId' => $chatwootContactId,
                'chatwootAccountId' => $espoAccountId
            ])
            ->findOne();

        if ($existingCwtContact) {
            $result = $this->updateExistingContact($existingCwtContact, $chatwootContact);
            $this->syncContactInboxes($existingCwtContact, $contactInboxes, $espoAccountId);
            return $result;
        }

        $cwtContact = $this->createNewContact($chatwootContact, $espoAccountId);
        $this->syncContactInboxes($cwtContact, $contactInboxes, $espoAccountId);
        return 'synced';
    }

    /**
     * Update an existing ChatwootContact from Chatwoot data.
     */
    private function updateExistingContact(Entity $cwtContact, array $chatwootContact): string
    {
        // Update the ChatwootContact record with latest Chatwoot data
        $cwtContact->set('name', $chatwootContact['name'] ?? null);
        $cwtContact->set('phoneNumber', $chatwootContact['phone_number'] ?? null);
        $cwtContact->set('email', $chatwootContact['email'] ?? null);
        $cwtContact->set('identifier', $chatwootContact['identifier'] ?? null);
        $cwtContact->set('availabilityStatus', $chatwootContact['availability_status'] ?? null);
        $cwtContact->set('blocked', $chatwootContact['blocked'] ?? false);
        $cwtContact->set('chatwootLastActivityAt', $this->convertChatwootTimestamp($chatwootContact['last_activity_at'] ?? null));
        $cwtContact->set('chatwootCreatedAt', $this->convertChatwootTimestamp($chatwootContact['created_at'] ?? null));
        $cwtContact->set('syncStatus', 'synced');
        $cwtContact->set('lastSyncedAt', date('Y-m-d H:i:s'));

        $this->entityManager->saveEntity($cwtContact, ['silent' => true]);

        // Update linked EspoCRM Contact if exists
        $contactId = $cwtContact->get('contactId');
        if ($contactId) {
            $this->updateEspoContact($contactId, $chatwootContact);
        }

        return 'synced';
    }

    /**
     * Create a new ChatwootContact and optionally link/create EspoCRM Contact.
     */
    private function createNewContact(array $chatwootContact, string $espoAccountId): Entity
    {
        // Try to find existing EspoCRM Contact by phone or email
        $espoContact = $this->findMatchingEspoContact($chatwootContact);

        // Create ChatwootContact bridge record
        $cwtContact = $this->entityManager->createEntity('ChatwootContact', [
            'chatwootContactId' => $chatwootContact['id'],
            'chatwootAccountId' => $espoAccountId,
            'contactId' => $espoContact?->getId(),
            'name' => $chatwootContact['name'] ?? null,
            'phoneNumber' => $chatwootContact['phone_number'] ?? null,
            'email' => $chatwootContact['email'] ?? null,
            'identifier' => $chatwootContact['identifier'] ?? null,
            'availabilityStatus' => $chatwootContact['availability_status'] ?? null,
            'blocked' => $chatwootContact['blocked'] ?? false,
            'chatwootLastActivityAt' => $this->convertChatwootTimestamp($chatwootContact['last_activity_at'] ?? null),
            'chatwootCreatedAt' => $this->convertChatwootTimestamp($chatwootContact['created_at'] ?? null),
            'syncStatus' => 'synced',
            'lastSyncedAt' => date('Y-m-d H:i:s'),
        ], ['silent' => true]);

        // Auto-create EspoCRM Contact if not found and we have enough data
        if (!$espoContact && $this->shouldAutoCreateEspoContact($chatwootContact)) {
            $espoContact = $this->createEspoContact($chatwootContact);
            
            $cwtContact->set('contactId', $espoContact->getId());
            $this->entityManager->saveEntity($cwtContact, ['silent' => true]);
        }

        return $cwtContact;
    }

    /**
     * Find matching EspoCRM Contact by phone number or email.
     */
    private function findMatchingEspoContact(array $chatwootContact): ?Entity
    {
        $phoneNumber = $chatwootContact['phone_number'] ?? null;
        $email = $chatwootContact['email'] ?? null;

        if ($phoneNumber) {
            $contact = $this->entityManager
                ->getRDBRepository('Contact')
                ->where(['phoneNumber' => $phoneNumber])
                ->findOne();

            if ($contact) {
                return $contact;
            }
        }

        if ($email) {
            $contact = $this->entityManager
                ->getRDBRepository('Contact')
                ->where(['emailAddress' => $email])
                ->findOne();

            if ($contact) {
                return $contact;
            }
        }

        return null;
    }

    /**
     * Update EspoCRM Contact from Chatwoot data (only fills empty fields).
     */
    private function updateEspoContact(string $contactId, array $chatwootContact): void
    {
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
     */
    private function createEspoContact(array $chatwootContact): Entity
    {
        $name = $chatwootContact['name'] ?? 'Unknown';
        $nameParts = explode(' ', $name, 2);

        return $this->entityManager->createEntity('Contact', [
            'firstName' => $nameParts[0] ?? '',
            'lastName' => $nameParts[1] ?? '',
            'phoneNumber' => $chatwootContact['phone_number'] ?? null,
            'emailAddress' => $chatwootContact['email'] ?? null,
            'description' => 'Imported from Chatwoot',
        ], ['silent' => true]);
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
     */
    private function syncContactInboxes(Entity $cwtContact, array $contactInboxes, string $espoAccountId): void
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
            
            // Generate display name
            $name = $inboxName ?? 'Inbox #' . $inboxId;
            if ($mappedChannelType) {
                $name .= ' (' . $mappedChannelType . ')';
            }

            // Check if ChatwootContactInbox already exists
            $existing = $this->entityManager
                ->getRDBRepository('ChatwootContactInbox')
                ->where([
                    'chatwootContactId' => $cwtContact->getId(),
                    'inboxId' => $inboxId,
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
                $existing->set('lastSyncedAt', date('Y-m-d H:i:s'));
                $this->entityManager->saveEntity($existing, ['silent' => true]);
            } else {
                // Create new
                $this->entityManager->createEntity('ChatwootContactInbox', [
                    'name' => $name,
                    'chatwootContactId' => $cwtContact->getId(),
                    'contactId' => $cwtContact->get('contactId'), // Denormalized
                    'chatwootAccountId' => $espoAccountId,
                    'inboxId' => $inboxId,
                    'inboxName' => $inboxName,
                    'inboxChannelType' => $mappedChannelType,
                    'sourceId' => $sourceId,
                    'lastSyncedAt' => date('Y-m-d H:i:s'),
                ], ['silent' => true]);
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
}

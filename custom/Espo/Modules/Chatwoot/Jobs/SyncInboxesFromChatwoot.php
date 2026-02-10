<?php

namespace Espo\Modules\Chatwoot\Jobs;

use Espo\Core\Job\JobDataLess;
use Espo\Core\Utils\Log;
use Espo\ORM\EntityManager;
use Espo\ORM\Entity;
use Espo\Modules\Chatwoot\Services\ChatwootApiClient;

/**
 * Scheduled job to sync inboxes from Chatwoot to EspoCRM.
 * Iterates through all ChatwootAccount records with contactSyncEnabled = true
 * and pulls inboxes from Chatwoot.
 */
class SyncInboxesFromChatwoot implements JobDataLess
{
    public function __construct(
        private EntityManager $entityManager,
        private ChatwootApiClient $apiClient,
        private Log $log
    ) {}

    public function run(): void
    {
        $this->log->debug('SyncInboxesFromChatwoot: Job started');

        try {
            $accounts = $this->getEnabledAccounts();
            $accountList = iterator_to_array($accounts);
            $accountCount = count($accountList);

            $this->log->debug("SyncInboxesFromChatwoot: Found {$accountCount} account(s) to sync");

            foreach ($accountList as $account) {
                $this->syncAccountInboxes($account);
            }

            $this->log->debug("SyncInboxesFromChatwoot: Job completed - processed {$accountCount} account(s)");
        } catch (\Throwable $e) {
            $this->log->error('SyncInboxesFromChatwoot: Job failed - ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
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
     * Sync inboxes for a single ChatwootAccount.
     */
    private function syncAccountInboxes(Entity $account): void
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

            // Get teams from the ChatwootAccount
            $teamsIds = $this->getAccountTeamsIds($account);

            // Sync inboxes
            $stats = $this->syncInboxes(
                $platformUrl,
                $apiKey,
                $chatwootAccountId,
                $account->getId(),
                $teamsIds
            );

            $this->log->debug(
                "SyncInboxesFromChatwoot: Account {$accountName} - " .
                "{$stats['synced']} synced, {$stats['deleted']} deleted, {$stats['errors']} errors"
            );

        } catch (\Exception $e) {
            $this->log->error(
                "SyncInboxesFromChatwoot: Sync failed for account {$accountName}: " . $e->getMessage()
            );
        }
    }

    /**
     * Sync inboxes from Chatwoot to EspoCRM.
     *
     * @param array<string> $teamsIds Team IDs to assign to synced entities
     * @return array{synced: int, errors: int, deleted: int}
     */
    private function syncInboxes(
        string $platformUrl,
        string $apiKey,
        int $chatwootAccountId,
        string $espoAccountId,
        array $teamsIds = []
    ): array {
        $stats = ['synced' => 0, 'errors' => 0, 'deleted' => 0];

        $response = $this->apiClient->listInboxes(
            $platformUrl,
            $apiKey,
            $chatwootAccountId
        );

        $inboxes = $response['payload'] ?? [];

        $this->log->debug(
            "SyncInboxesFromChatwoot: Found " . count($inboxes) . " inboxes"
        );

        // Collect Chatwoot inbox IDs that exist
        $chatwootInboxIds = [];
        
        foreach ($inboxes as $chatwootInbox) {
            try {
                $this->syncSingleInbox($chatwootInbox, $espoAccountId, $teamsIds);
                $stats['synced']++;
                $chatwootInboxIds[] = $chatwootInbox['id'];
            } catch (\Exception $e) {
                $stats['errors']++;
                $inboxId = $chatwootInbox['id'] ?? 'unknown';
                $this->log->debug(
                    "SyncInboxesFromChatwoot: Failed to sync inbox {$inboxId}: " . $e->getMessage()
                );
            }
        }

        // Remove orphaned inboxes (exist in EspoCRM but not in Chatwoot)
        $deleted = $this->removeOrphanedInboxes($espoAccountId, $chatwootInboxIds);
        $stats['deleted'] = $deleted;

        return $stats;
    }

    /**
     * Remove ChatwootInbox records that no longer exist in Chatwoot.
     *
     * @param string $espoAccountId
     * @param array<int> $chatwootInboxIds Valid Chatwoot inbox IDs
     * @return int Number of deleted records
     */
    private function removeOrphanedInboxes(string $espoAccountId, array $chatwootInboxIds): int
    {
        $deleted = 0;

        // Get all existing inboxes for this account in EspoCRM
        $existingInboxes = $this->entityManager
            ->getRDBRepository('ChatwootInbox')
            ->where(['chatwootAccountId' => $espoAccountId])
            ->find();

        foreach ($existingInboxes as $inbox) {
            $inboxChatwootId = $inbox->get('chatwootInboxId');
            
            // If this inbox's chatwootInboxId is not in the list from Chatwoot, delete it
            if (!in_array($inboxChatwootId, $chatwootInboxIds, true)) {
                $this->log->info(
                    "SyncInboxesFromChatwoot: Removing orphaned inbox {$inbox->getId()} " .
                    "(chatwootInboxId: {$inboxChatwootId}) - no longer exists in Chatwoot"
                );
                
                try {
                    // Use cascadeParent to enable local cascade delete while skipping remote API calls
                    $this->entityManager->removeEntity($inbox, ['cascadeParent' => true]);
                    $deleted++;
                } catch (\Exception $e) {
                    $this->log->debug(
                        "SyncInboxesFromChatwoot: Failed to remove orphaned inbox {$inbox->getId()}: " .
                        $e->getMessage()
                    );
                }
            }
        }

        return $deleted;
    }

    /**
     * Sync a single inbox from Chatwoot to EspoCRM.
     *
     * @param array<string> $teamsIds Team IDs to assign to synced entities
     */
    private function syncSingleInbox(array $chatwootInbox, string $espoAccountId, array $teamsIds = []): void
    {
        $chatwootInboxId = $chatwootInbox['id'];

        // Check if ChatwootInbox already exists
        $existingInbox = $this->entityManager
            ->getRDBRepository('ChatwootInbox')
            ->where([
                'chatwootInboxId' => $chatwootInboxId,
                'chatwootAccountId' => $espoAccountId,
            ])
            ->findOne();

        if ($existingInbox) {
            $this->updateExistingInbox($existingInbox, $chatwootInbox, $teamsIds);
        } else {
            $this->createNewInbox($chatwootInbox, $espoAccountId, $teamsIds);
        }
    }

    /**
     * Update an existing ChatwootInbox from Chatwoot data.
     *
     * @param array<string> $teamsIds Team IDs to assign to synced entities
     */
    private function updateExistingInbox(Entity $inbox, array $chatwootInbox, array $teamsIds = []): void
    {
        $inbox->set('name', $chatwootInbox['name'] ?? 'Inbox #' . $chatwootInbox['id']);
        $inbox->set('channelType', $chatwootInbox['channel_type'] ?? null);
        $inbox->set('websiteUrl', $chatwootInbox['website_url'] ?? null);
        $inbox->set('phoneNumber', $chatwootInbox['phone_number'] ?? null);
        $inbox->set('provider', $chatwootInbox['provider'] ?? null);
        $inbox->set('medium', $chatwootInbox['medium'] ?? null);
        $inbox->set('greetingEnabled', $chatwootInbox['greeting_enabled'] ?? false);
        $inbox->set('greetingMessage', $chatwootInbox['greeting_message'] ?? null);
        $inbox->set('avatarUrl', $chatwootInbox['avatar_url'] ?? null);
        $inbox->set('inboxIdentifier', $chatwootInbox['inbox_identifier'] ?? null);
        $inbox->set('lastSyncedAt', date('Y-m-d H:i:s'));

        // Auto-link to ChatwootInboxIntegration if not already linked
        if (!$inbox->get('chatwootInboxIntegrationId') && !empty($chatwootInbox['inbox_identifier'])) {
            $integration = $this->findIntegrationByIdentifier($chatwootInbox['inbox_identifier']);
            if ($integration) {
                $inbox->set('chatwootInboxIntegrationId', $integration->getId());
                // Also update the integration with the chatwootInboxId
                if (!$integration->get('chatwootInboxId')) {
                    $integration->set('chatwootInboxId', $chatwootInbox['id']);
                    $this->entityManager->saveEntity($integration, ['silent' => true]);
                }
                $this->log->info(
                    "SyncInboxesFromChatwoot: Linked inbox {$inbox->getId()} to integration {$integration->getId()}"
                );
            }
        }

        // Assign teams from ChatwootAccount
        if (!empty($teamsIds)) {
            $inbox->set('teamsIds', $teamsIds);
        }

        $this->entityManager->saveEntity($inbox, ['silent' => true]);
    }

    /**
     * Create a new ChatwootInbox from Chatwoot data.
     *
     * @param array<string> $teamsIds Team IDs to assign to synced entities
     */
    private function createNewInbox(array $chatwootInbox, string $espoAccountId, array $teamsIds = []): void
    {
        $data = [
            'name' => $chatwootInbox['name'] ?? 'Inbox #' . $chatwootInbox['id'],
            'chatwootInboxId' => $chatwootInbox['id'],
            'chatwootAccountId' => $espoAccountId,
            'channelType' => $chatwootInbox['channel_type'] ?? null,
            'websiteUrl' => $chatwootInbox['website_url'] ?? null,
            'phoneNumber' => $chatwootInbox['phone_number'] ?? null,
            'provider' => $chatwootInbox['provider'] ?? null,
            'medium' => $chatwootInbox['medium'] ?? null,
            'greetingEnabled' => $chatwootInbox['greeting_enabled'] ?? false,
            'greetingMessage' => $chatwootInbox['greeting_message'] ?? null,
            'avatarUrl' => $chatwootInbox['avatar_url'] ?? null,
            'inboxIdentifier' => $chatwootInbox['inbox_identifier'] ?? null,
            'lastSyncedAt' => date('Y-m-d H:i:s'),
        ];

        // Try to find and link ChatwootInboxIntegration
        if (!empty($chatwootInbox['inbox_identifier'])) {
            $integration = $this->findIntegrationByIdentifier($chatwootInbox['inbox_identifier']);
            if ($integration) {
                $data['chatwootInboxIntegrationId'] = $integration->getId();
                // Also update the integration with the chatwootInboxId
                if (!$integration->get('chatwootInboxId')) {
                    $integration->set('chatwootInboxId', $chatwootInbox['id']);
                    $this->entityManager->saveEntity($integration, ['silent' => true]);
                }
                $this->log->info(
                    "SyncInboxesFromChatwoot: Auto-linked new inbox to integration {$integration->getId()}"
                );
            }
        }

        // Assign teams from ChatwootAccount
        if (!empty($teamsIds)) {
            $data['teamsIds'] = $teamsIds;
        }

        $this->entityManager->createEntity('ChatwootInbox', $data, ['silent' => true]);
    }

    /**
     * Find ChatwootInboxIntegration by inbox identifier.
     */
    private function findIntegrationByIdentifier(string $identifier): ?Entity
    {
        return $this->entityManager
            ->getRDBRepository('ChatwootInboxIntegration')
            ->where(['chatwootInboxIdentifier' => $identifier])
            ->findOne();
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
}






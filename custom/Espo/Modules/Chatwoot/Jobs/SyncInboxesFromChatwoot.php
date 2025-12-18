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
        $this->log->warning('SyncInboxesFromChatwoot: Job started');

        try {
            $accounts = $this->getEnabledAccounts();
            $accountList = iterator_to_array($accounts);
            $accountCount = count($accountList);

            $this->log->warning("SyncInboxesFromChatwoot: Found {$accountCount} account(s) to sync");

            foreach ($accountList as $account) {
                $this->syncAccountInboxes($account);
            }

            $this->log->warning("SyncInboxesFromChatwoot: Job completed - processed {$accountCount} account(s)");
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

            $platformUrl = $platform->get('url');
            $apiKey = $account->get('apiKey');
            $chatwootAccountId = $account->get('chatwootAccountId');

            if (!$platformUrl || !$apiKey || !$chatwootAccountId) {
                throw new \Exception('Missing platform URL, API key, or Chatwoot account ID');
            }

            // Sync inboxes
            $stats = $this->syncInboxes(
                $platformUrl,
                $apiKey,
                $chatwootAccountId,
                $account->getId()
            );

            $this->log->warning(
                "SyncInboxesFromChatwoot: Account {$accountName} - " .
                "{$stats['synced']} synced, {$stats['errors']} errors"
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
     * @return array{synced: int, errors: int}
     */
    private function syncInboxes(
        string $platformUrl,
        string $apiKey,
        int $chatwootAccountId,
        string $espoAccountId
    ): array {
        $stats = ['synced' => 0, 'errors' => 0];

        $response = $this->apiClient->listInboxes(
            $platformUrl,
            $apiKey,
            $chatwootAccountId
        );

        $inboxes = $response['payload'] ?? [];

        $this->log->warning(
            "SyncInboxesFromChatwoot: Found " . count($inboxes) . " inboxes"
        );

        foreach ($inboxes as $chatwootInbox) {
            try {
                $this->syncSingleInbox($chatwootInbox, $espoAccountId);
                $stats['synced']++;
            } catch (\Exception $e) {
                $stats['errors']++;
                $inboxId = $chatwootInbox['id'] ?? 'unknown';
                $this->log->warning(
                    "SyncInboxesFromChatwoot: Failed to sync inbox {$inboxId}: " . $e->getMessage()
                );
            }
        }

        return $stats;
    }

    /**
     * Sync a single inbox from Chatwoot to EspoCRM.
     */
    private function syncSingleInbox(array $chatwootInbox, string $espoAccountId): void
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
            $this->updateExistingInbox($existingInbox, $chatwootInbox);
        } else {
            $this->createNewInbox($chatwootInbox, $espoAccountId);
        }
    }

    /**
     * Update an existing ChatwootInbox from Chatwoot data.
     */
    private function updateExistingInbox(Entity $inbox, array $chatwootInbox): void
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
        $inbox->set('lastSyncedAt', date('Y-m-d H:i:s'));

        $this->entityManager->saveEntity($inbox, ['silent' => true]);
    }

    /**
     * Create a new ChatwootInbox from Chatwoot data.
     */
    private function createNewInbox(array $chatwootInbox, string $espoAccountId): void
    {
        $this->entityManager->createEntity('ChatwootInbox', [
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
            'lastSyncedAt' => date('Y-m-d H:i:s'),
        ], ['silent' => true]);
    }
}

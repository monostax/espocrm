<?php

namespace Espo\Modules\Chatwoot\Jobs;

use Espo\Core\Job\JobDataLess;
use Espo\Core\Utils\Log;
use Espo\ORM\EntityManager;
use Espo\ORM\Entity;
use Espo\Modules\Chatwoot\Services\ChatwootApiClient;
use Espo\Modules\Chatwoot\Services\SyncTwilioCredentials;

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
        private Log $log,
        private SyncTwilioCredentials $syncTwilioCredentials
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

                // Mirror Twilio VoIP credentials into a CRM Credential so the
                // CRM can proxy call recordings server-side. Only hits the
                // privileged Chatwoot endpoint when the inbox has VoIP enabled.
                $this->syncTwilioCredentials->syncForInbox(
                    $platformUrl,
                    $apiKey,
                    $chatwootAccountId,
                    $chatwootInbox,
                    $teamsIds
                );
            } catch (\Exception $e) {
                $stats['errors']++;
                $inboxId = $chatwootInbox['id'] ?? 'unknown';
                $this->log->debug(
                    "SyncInboxesFromChatwoot: Failed to sync inbox {$inboxId}: " . $e->getMessage()
                );
            }
        }

        // Remove orphaned inboxes (exist in EspoCRM but not in Chatwoot)
        $deleted = $this->removeOrphanedInboxes(
            $espoAccountId,
            $chatwootInboxIds,
            $platformUrl,
            $apiKey,
            $chatwootAccountId
        );
        $stats['deleted'] = $deleted;

        return $stats;
    }

    /**
     * Remove ChatwootInbox records that no longer exist in Chatwoot.
     *
     * SAFETY layers (in order):
     *   1. If the API returned 0 inboxes but local inboxes exist, abort entirely —
     *      almost certainly a transient API failure, not "all inboxes were deleted".
     *   2. For each local inbox not in the listInboxes response, perform a
     *      targeted GET /api/v1/accounts/{id}/inboxes/{inbox_id} and only delete
     *      on confirmed 404. If the inbox still exists remotely we log a warning
     *      and skip — this prevents silent data loss when Chatwoot returns a
     *      partial or stale inbox list.
     *   3. If the per-inbox confirmation itself errors out, skip that inbox.
     *
     * @param string $espoAccountId
     * @param array<int> $chatwootInboxIds Valid Chatwoot inbox IDs from listInboxes
     * @param string $platformUrl Chatwoot platform base URL
     * @param string $apiKey Chatwoot account API key
     * @param int $chatwootAccountId Chatwoot-side account ID
     * @return int Number of deleted records
     */
    private function removeOrphanedInboxes(
        string $espoAccountId,
        array $chatwootInboxIds,
        string $platformUrl,
        string $apiKey,
        int $chatwootAccountId
    ): int {
        $deleted = 0;

        // Get all existing inboxes for this account in EspoCRM
        $existingInboxes = $this->entityManager
            ->getRDBRepository('ChatwootInbox')
            ->where(['chatwootAccountId' => $espoAccountId])
            ->find();

        $existingList = iterator_to_array($existingInboxes);

        // Layer 1: refuse to treat an empty API response as "delete all".
        if (empty($chatwootInboxIds) && !empty($existingList)) {
            $this->log->warning(
                "SyncInboxesFromChatwoot: API returned 0 inboxes but " . count($existingList) .
                " local inboxes exist for account {$espoAccountId}. " .
                "Skipping destructive cleanup to prevent data loss."
            );
            return 0;
        }

        foreach ($existingList as $inbox) {
            $inboxChatwootId = $inbox->get('chatwootInboxId');

            if (in_array($inboxChatwootId, $chatwootInboxIds, true)) {
                continue;
            }

            if (empty($inboxChatwootId)) {
                // Local inbox without a Chatwoot id — cannot confirm remote state.
                $this->log->warning(
                    "SyncInboxesFromChatwoot: Local inbox {$inbox->getId()} has no " .
                    "chatwootInboxId; skipping orphan cleanup."
                );
                continue;
            }

            // Layer 2: confirm the inbox really is missing remotely before deleting.
            try {
                $remote = $this->apiClient->getInbox(
                    $platformUrl,
                    $apiKey,
                    $chatwootAccountId,
                    (int) $inboxChatwootId
                );
            } catch (\Throwable $e) {
                // Layer 3: on API error, do not delete.
                $this->log->warning(
                    "SyncInboxesFromChatwoot: Could not confirm remote state of inbox " .
                    "{$inboxChatwootId} for account {$espoAccountId}: " . $e->getMessage() .
                    " - skipping deletion."
                );
                continue;
            }

            if ($remote !== null) {
                // Inbox exists remotely but was missing from the list response.
                // This is exactly the bug class that previously destroyed data.
                $this->log->warning(
                    "SyncInboxesFromChatwoot: Inbox {$inboxChatwootId} was absent from " .
                    "listInboxes but direct GET returned 200 for account {$espoAccountId}. " .
                    "Skipping deletion of local inbox {$inbox->getId()}."
                );
                continue;
            }

            // Confirmed 404 — safe to delete.
            $this->log->info(
                "SyncInboxesFromChatwoot: Removing orphaned inbox {$inbox->getId()} " .
                "(chatwootInboxId: {$inboxChatwootId}) - confirmed 404 in Chatwoot"
            );

            try {
                $this->entityManager->removeEntity($inbox, ['cascadeParent' => true]);
                $deleted++;
            } catch (\Exception $e) {
                $this->log->debug(
                    "SyncInboxesFromChatwoot: Failed to remove orphaned inbox {$inbox->getId()}: " .
                    $e->getMessage()
                );
            }
        }

        return $deleted;
    }

    /**
     * Sync a single inbox from Chatwoot to EspoCRM.
     *
     * Self-heals soft-deleted local records: if a ChatwootInbox for this
     * (chatwootInboxId, chatwootAccountId) pair was previously soft-deleted
     * but Chatwoot still returns the inbox, we restore the local row rather
     * than create a duplicate. This protects against the data-loss class
     * where a prior sync run incorrectly marked an existing inbox as orphaned.
     *
     * @param array<string> $teamsIds Team IDs to assign to synced entities
     */
    private function syncSingleInbox(array $chatwootInbox, string $espoAccountId, array $teamsIds = []): void
    {
        $chatwootInboxId = $chatwootInbox['id'];

        // Check if ChatwootInbox already exists (live rows only).
        $existingInbox = $this->entityManager
            ->getRDBRepository('ChatwootInbox')
            ->where([
                'chatwootInboxId' => $chatwootInboxId,
                'chatwootAccountId' => $espoAccountId,
            ])
            ->findOne();

        if ($existingInbox) {
            $this->updateExistingInbox($existingInbox, $chatwootInbox, $teamsIds);
            return;
        }

        // Fall back to soft-deleted lookup and self-heal.
        $softDeleted = $this->findInboxIncludingDeleted($chatwootInboxId, $espoAccountId);

        if ($softDeleted) {
            $this->log->warning(
                "SyncInboxesFromChatwoot: Restoring soft-deleted ChatwootInbox " .
                "{$softDeleted->getId()} (chatwootInboxId={$chatwootInboxId}) — " .
                "Chatwoot still returns this inbox."
            );

            $this->entityManager
                ->getRDBRepository('ChatwootInbox')
                ->restoreDeleted($softDeleted->getId());

            $restored = $this->entityManager->getEntityById('ChatwootInbox', $softDeleted->getId());

            if ($restored) {
                $this->updateExistingInbox($restored, $chatwootInbox, $teamsIds);
                return;
            }
        }

        $this->createNewInbox($chatwootInbox, $espoAccountId, $teamsIds);
    }

    /**
     * Find a ChatwootInbox by (chatwootInboxId, chatwootAccountId) including
     * soft-deleted records. Needed for self-healing restore.
     */
    private function findInboxIncludingDeleted(int $chatwootInboxId, string $espoAccountId): ?Entity
    {
        $query = $this->entityManager
            ->getQueryBuilder()
            ->select()
            ->from('ChatwootInbox')
            ->where([
                'chatwootInboxId' => $chatwootInboxId,
                'chatwootAccountId' => $espoAccountId,
            ])
            ->withDeleted()
            ->build();

        return $this->entityManager
            ->getRDBRepository('ChatwootInbox')
            ->clone($query)
            ->findOne();
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
        if (!$inbox->get('chatwootInboxIntegrationId')) {
            $integration = $this->findIntegrationForInbox($chatwootInbox, $inbox->get('chatwootAccountId'));
            if ($integration) {
                $this->linkInboxToIntegration($inbox, $integration, $chatwootInbox['id']);
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
        $integration = $this->findIntegrationForInbox($chatwootInbox, $espoAccountId);
        if ($integration) {
            $data['chatwootInboxIntegrationId'] = $integration->getId();
            $this->updateIntegrationInboxId($integration, $chatwootInbox['id']);
        }

        // Assign teams from ChatwootAccount
        if (!empty($teamsIds)) {
            $data['teamsIds'] = $teamsIds;
        }

        $this->entityManager->createEntity('ChatwootInbox', $data, ['silent' => true]);
    }

    /**
     * Find a matching ChatwootInboxIntegration for a Chatwoot inbox.
     * Uses inbox_identifier for QRCode/API inboxes, falls back to phone number
     * matching for WhatsApp Cloud API (Channel::Whatsapp) inboxes.
     */
    private function findIntegrationForInbox(array $chatwootInbox, string $espoAccountId): ?Entity
    {
        // Strategy 1: Match by inbox_identifier (works for QRCode/API inboxes)
        $identifier = $chatwootInbox['inbox_identifier'] ?? null;

        if ($identifier) {
            $integration = $this->entityManager
                ->getRDBRepository('ChatwootInboxIntegration')
                ->where(['chatwootInboxIdentifier' => $identifier])
                ->findOne();

            if ($integration) {
                return $integration;
            }
        }

        // Strategy 2: Match by phone number for WhatsApp Cloud API inboxes
        $phoneNumber = $chatwootInbox['phone_number'] ?? null;
        $channelType = $chatwootInbox['channel_type'] ?? null;

        if ($phoneNumber && $channelType === 'Channel::Whatsapp') {
            $normalizedInboxPhone = preg_replace('/[^0-9]/', '', $phoneNumber);

            $integrations = $this->entityManager
                ->getRDBRepository('ChatwootInboxIntegration')
                ->where([
                    'chatwootAccountId' => $espoAccountId,
                    'channelType' => 'whatsappCloudApi',
                ])
                ->find();

            foreach ($integrations as $integration) {
                $integrationPhone = preg_replace('/[^0-9]/', '', $integration->get('phoneNumber') ?? '');
                if ($normalizedInboxPhone && $integrationPhone && $normalizedInboxPhone === $integrationPhone) {
                    $this->log->info(
                        "SyncInboxesFromChatwoot: Matched inbox to integration {$integration->getId()} by phone number"
                    );
                    return $integration;
                }
            }
        }

        return null;
    }

    /**
     * Link a ChatwootInbox to a ChatwootInboxIntegration and update the
     * integration's chatwootInboxId if not already set.
     */
    private function linkInboxToIntegration(Entity $inbox, Entity $integration, int $chatwootInboxId): void
    {
        $inbox->set('chatwootInboxIntegrationId', $integration->getId());
        $this->updateIntegrationInboxId($integration, $chatwootInboxId);

        $this->log->info(
            "SyncInboxesFromChatwoot: Linked inbox {$inbox->getId()} to integration {$integration->getId()}"
        );
    }

    /**
     * Update the integration's chatwootInboxId if not already populated.
     */
    private function updateIntegrationInboxId(Entity $integration, int $chatwootInboxId): void
    {
        if (!$integration->get('chatwootInboxId')) {
            $integration->set('chatwootInboxId', $chatwootInboxId);
            $this->entityManager->saveEntity($integration, ['silent' => true]);
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

}

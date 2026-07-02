<?php
/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

namespace Espo\Modules\Chatwoot\Jobs;

use Espo\Core\Job\JobDataLess;
use Espo\Core\Utils\Log;
use Espo\ORM\EntityManager;
use Espo\ORM\Entity;
use Espo\Modules\Chatwoot\Services\ChatwootApiClient;
use Espo\Modules\Chatwoot\Services\ChatwootAccountUserMembershipService;
use Espo\Modules\Chatwoot\Services\WahaApiClient;

/**
 * Scheduled job to sync inbox members from Chatwoot to EspoCRM.
 *
 * Resolves remote inbox members via membership-first lookup
 * (ChatwootUser.chatwootUserId -> ChatwootAccountUserMembership)
 * and writes the inbox<->membership relation as the sole source of truth.
 * WAHA labels are derived directly from memberships (no ChatwootAgent).
 */
class SyncInboxMembersFromChatwoot implements JobDataLess
{
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
        private Log $log,
        private ChatwootAccountUserMembershipService $membershipService
    ) {}

    public function run(): void
    {
        $this->log->info('SyncInboxMembersFromChatwoot: Job started');

        try {
            $accounts = $this->getEnabledAccounts();
            $accountList = iterator_to_array($accounts);
            $accountCount = count($accountList);

            $this->log->info("SyncInboxMembersFromChatwoot: Found {$accountCount} account(s) to sync");

            foreach ($accountList as $account) {
                $this->syncAccountInboxMembers($account);
            }

            $this->log->info("SyncInboxMembersFromChatwoot: Job completed - processed {$accountCount} account(s)");
        } catch (\Throwable $e) {
            $this->log->error('SyncInboxMembersFromChatwoot: Job failed - ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
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
     * Sync inbox members for a single ChatwootAccount.
     */
    private function syncAccountInboxMembers(Entity $account): void
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
            $platformId = $account->get('platformId');

            if (!$platformUrl || !$apiKey || !$chatwootAccountId) {
                throw new \Exception('Missing platform URL, API key, or Chatwoot account ID');
            }

            // Get all inboxes for this account
            $inboxes = $this->entityManager
                ->getRDBRepository('ChatwootInbox')
                ->where(['chatwootAccountId' => $account->getId()])
                ->find();

            $totalSynced = 0;
            $totalErrors = 0;

            foreach ($inboxes as $inbox) {
                $stats = $this->syncInboxMembers(
                    $platformUrl,
                    $apiKey,
                    $chatwootAccountId,
                    $inbox,
                    $account->getId(),
                    $platformId
                );

                $totalSynced += $stats['synced'];
                $totalErrors += $stats['errors'];
            }

            $this->log->info(
                "SyncInboxMembersFromChatwoot: Account {$accountName} - " .
                "{$totalSynced} synced, {$totalErrors} errors"
            );

        } catch (\Exception $e) {
            $this->log->error(
                "SyncInboxMembersFromChatwoot: Sync failed for account {$accountName}: " . $e->getMessage()
            );
        }
    }

    /**
     * Sync members for a single inbox.
     *
     * Uses membership-first resolution: each remote member's platform user ID
     * is resolved to a ChatwootAccountUserMembership. The inbox<->membership
     * relation is the sole relation managed. WAHA labels are derived from
     * memberships directly.
     *
     * @return array{synced: int, errors: int}
     */
    private function syncInboxMembers(
        string $platformUrl,
        string $apiKey,
        int $chatwootAccountId,
        Entity $inbox,
        string $espoAccountId,
        string $platformId
    ): array {
        $stats = ['synced' => 0, 'errors' => 0];

        $chatwootInboxId = $inbox->get('chatwootInboxId');
        
        if (!$chatwootInboxId) {
            return $stats;
        }

        try {
            // Fetch inbox members from Chatwoot API
            try {
                $members = $this->apiClient->listInboxMembers(
                    $platformUrl,
                    $apiKey,
                    $chatwootAccountId,
                    $chatwootInboxId
                );
            } catch (\Exception $e) {
                // If inbox doesn't exist in Chatwoot (404), skip silently
                if (str_contains($e->getMessage(), '404')) {
                    $this->log->debug(
                        "SyncInboxMembersFromChatwoot: Inbox {$chatwootInboxId} not found in Chatwoot, skipping"
                    );
                    return $stats;
                }
                throw $e;
            }

            $this->log->debug(
                "SyncInboxMembersFromChatwoot: Found " . count($members) . " members for inbox {$chatwootInboxId}"
            );

            // --- Membership-first resolution ---
            /** @var array<string, Entity> $membershipsToLink membershipId => membership entity */
            $membershipsToLink = [];
            /** @var array<int> $resolvedRemoteUserIds platform user IDs that were successfully resolved */
            $resolvedRemoteUserIds = [];

            foreach ($members as $member) {
                $chatwootPlatformUserId = (int) ($member['id'] ?? 0);

                if (!$chatwootPlatformUserId) {
                    continue;
                }

                $membership = $this->membershipService->resolveMembershipByPlatformUserId(
                    $chatwootPlatformUserId,
                    $platformId,
                    $espoAccountId
                );

                if (!$membership) {
                    $this->log->debug(
                        "SyncInboxMembersFromChatwoot: No membership found for platform user {$chatwootPlatformUserId} " .
                        "in account {$espoAccountId}, skipping (user not yet synced)"
                    );
                    continue;
                }

                $resolvedRemoteUserIds[] = $chatwootPlatformUserId;
                $membershipsToLink[$membership->getId()] = $membership;
            }

            // --- Build previous membership IDs for label reconciliation ---
            $previousMembershipIds = [];
            $currentMemberships = $this->entityManager
                ->getRDBRepository('ChatwootInbox')
                ->getRelation($inbox, 'accountUserMemberships')
                ->find();

            $currentMembershipIds = [];

            foreach ($currentMemberships as $m) {
                $currentMembershipIds[] = $m->getId();
                $previousMembershipIds[$m->getId()] = $m->getId();
            }

            // Fallback path: also query WahaSessionLabel records
            // for this inbox integration to catch labels for memberships whose relation is stale/deleted.
            $inboxIntegration = $this->findIntegrationForInbox($inbox);

            if ($inboxIntegration) {
                $existingLabels = $this->entityManager
                    ->getRDBRepository('WahaSessionLabel')
                    ->where(['inboxIntegrationId' => $inboxIntegration->getId()])
                    ->find();

                foreach ($existingLabels as $label) {
                    $labelMembershipId = $label->get('accountUserMembershipId');

                    if ($labelMembershipId) {
                        $previousMembershipIds[$labelMembershipId] = $labelMembershipId;
                    }
                }
            }

            $previousMembershipIds = array_values($previousMembershipIds);

            // --- Safety guards ---
            $desiredMembershipIds = array_keys($membershipsToLink);

            // Partial-resolution guard: If some API members couldn't be resolved locally,
            // only perform additions — skip the removal pass.
            $hasUnresolvedMembers = count($resolvedRemoteUserIds) < count($members);

            if ($hasUnresolvedMembers) {
                $this->log->debug(
                    "SyncInboxMembersFromChatwoot: Inbox {$chatwootInboxId} — " .
                    count($resolvedRemoteUserIds) . '/' . count($members) . ' members resolved. ' .
                    'Skipping removal pass (partial resolution).'
                );
            }

            // --- Reconcile inbox<->membership relation ---
            $membershipIdsToAdd = array_diff($desiredMembershipIds, $currentMembershipIds);
            $membershipIdsToRemove = $hasUnresolvedMembers
                ? [] // Skip removals when partially resolved
                : array_diff($currentMembershipIds, $desiredMembershipIds);

            // Add new membership links
            foreach ($membershipIdsToAdd as $membershipId) {
                try {
                    $this->entityManager
                        ->getRDBRepository('ChatwootInbox')
                        ->getRelation($inbox, 'accountUserMemberships')
                        ->relateById($membershipId, null, ['skipHooks' => true]);

                    $stats['synced']++;
                    $this->log->debug("SyncInboxMembersFromChatwoot: Linked membership {$membershipId} to inbox {$inbox->getId()}");
                } catch (\Exception $e) {
                    $stats['errors']++;
                    $this->log->debug(
                        "SyncInboxMembersFromChatwoot: Failed to link membership {$membershipId} to inbox: " . $e->getMessage()
                    );
                }
            }

            // Remove old membership links
            foreach ($membershipIdsToRemove as $membershipId) {
                try {
                    $this->entityManager
                        ->getRDBRepository('ChatwootInbox')
                        ->getRelation($inbox, 'accountUserMemberships')
                        ->unrelateById($membershipId, ['skipHooks' => true]);

                    $stats['synced']++;
                    $this->log->debug("SyncInboxMembersFromChatwoot: Unlinked membership {$membershipId} from inbox {$inbox->getId()}");
                } catch (\Exception $e) {
                    $stats['errors']++;
                    $this->log->debug(
                        "SyncInboxMembersFromChatwoot: Failed to unlink membership {$membershipId} from inbox: " . $e->getMessage()
                    );
                }
            }

            // --- WAHA label lifecycle (derived from memberships directly) ---
            if ($inboxIntegration) {
                $desiredMembershipIdsForLabels = $desiredMembershipIds;

                // Removed memberships: delete labels first to free up slots
                $removedMembershipIds = array_diff($previousMembershipIds, $desiredMembershipIdsForLabels);

                foreach ($removedMembershipIds as $membershipId) {
                    $this->deleteLabelForMembershipInbox($membershipId, $inboxIntegration);
                }

                // Added memberships: create labels
                $addedMembershipIds = array_diff($desiredMembershipIdsForLabels, $previousMembershipIds);
                $labelLimitReached = false;

                foreach ($addedMembershipIds as $membershipId) {
                    if ($labelLimitReached) {
                        break;
                    }
                    if (isset($membershipsToLink[$membershipId])) {
                        $labelLimitReached = !$this->createLabelForMembershipInbox($membershipsToLink[$membershipId], $inboxIntegration);
                    }
                }

                // Reconcile labels for all desired memberships (create missing labels)
                if (!$labelLimitReached) {
                    foreach ($membershipsToLink as $membership) {
                        if ($labelLimitReached) {
                            break;
                        }
                        $labelLimitReached = !$this->reconcileLabelForMembershipInbox($membership, $inboxIntegration);
                    }
                }
            }

        } catch (\Exception $e) {
            $stats['errors']++;
            $this->log->debug(
                "SyncInboxMembersFromChatwoot: Failed to sync inbox {$chatwootInboxId}: " . $e->getMessage()
            );
        }

        return $stats;
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
     * Reconcile label for an existing membership-inbox combination (create if missing).
     *
     * @return bool True if successful or already exists, false if further label
     *   creation for this inbox should stop (see createLabelForMembershipInbox).
     */
    private function reconcileLabelForMembershipInbox(Entity $membership, Entity $inboxIntegration): bool
    {
        // Check if label already exists
        $existingLabel = $this->entityManager
            ->getRDBRepository('WahaSessionLabel')
            ->where([
                'accountUserMembershipId' => $membership->getId(),
                'inboxIntegrationId' => $inboxIntegration->getId(),
            ])
            ->findOne();

        if ($existingLabel) {
            return true;
        }

        // Label doesn't exist, create it
        return $this->createLabelForMembershipInbox($membership, $inboxIntegration);
    }

    /**
     * Create a WAHA label for a membership-inbox combination.
     *
     * @return bool True if successful or skipped (non-fatal), false if further
     *   label creation for this inbox should stop (WAHA label limit reached or
     *   the WAHA session is unavailable).
     */
    private function createLabelForMembershipInbox(Entity $membership, Entity $inboxIntegration): bool
    {
        try {
            // Check if label already exists
            $existingLabel = $this->entityManager
                ->getRDBRepository('WahaSessionLabel')
                ->where([
                    'accountUserMembershipId' => $membership->getId(),
                    'inboxIntegrationId' => $inboxIntegration->getId(),
                ])
                ->findOne();

            if ($existingLabel) {
                $this->log->debug("SyncInboxMembersFromChatwoot: Label already exists for membership {$membership->getId()} + integration {$inboxIntegration->getId()}");
                return true;
            }

            // Get WAHA platform and session info
            $wahaPlatformId = $inboxIntegration->get('wahaPlatformId');

            if (!$wahaPlatformId) {
                $this->log->debug("SyncInboxMembersFromChatwoot: No wahaPlatformId for integration {$inboxIntegration->getId()}, skipping label creation");
                return true;
            }

            $wahaPlatform = $this->entityManager->getEntityById(
                'WahaPlatform',
                $wahaPlatformId
            );

            if (!$wahaPlatform) {
                $this->log->debug("SyncInboxMembersFromChatwoot: WahaPlatform not found for integration {$inboxIntegration->getId()}");
                return true;
            }

            $platformUrl = $wahaPlatform->get('backendUrl');
            $apiKey = $wahaPlatform->get('apiKey');
            $sessionName = $inboxIntegration->get('wahaSessionName');

            if (!$platformUrl || !$apiKey || !$sessionName) {
                $this->log->debug("SyncInboxMembersFromChatwoot: Missing WAHA credentials or session name");
                return true;
            }

            // Generate label name and color based on membership type
            $labelPrefix = $membership->get('isAI') ? '[✨]' : '[👤]';
            $labelName = $labelPrefix . ' ' . $membership->get('name');
            $color = abs(crc32($membership->getId())) % 20;
            $colorHex = self::COLOR_MAP[$color] ?? '#64c4ff';

            // Create label in WAHA
            $this->log->info("SyncInboxMembersFromChatwoot: Creating WAHA label '{$labelName}' for session {$sessionName}");

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
                $this->log->error("SyncInboxMembersFromChatwoot: WAHA response missing label ID");
                return true;
            }

            // Create WahaSessionLabel record
            $this->entityManager->createEntity('WahaSessionLabel', [
                'name' => $labelName,
                'wahaLabelId' => (string) $wahaLabelId,
                'color' => $color,
                'colorHex' => $wahaResponse['colorHex'] ?? $colorHex,
                'accountUserMembershipId' => $membership->getId(),
                'inboxIntegrationId' => $inboxIntegration->getId(),
                'teamsIds' => $inboxIntegration->getLinkMultipleIdList('teams'),
                'syncStatus' => 'synced',
            ], ['silent' => true]);

            $this->log->info("SyncInboxMembersFromChatwoot: Created WahaSessionLabel for membership {$membership->getId()} with WAHA ID {$wahaLabelId}");
            return true;

        } catch (\Exception $e) {
            $message = $e->getMessage();

            // Detect WAHA label limit (WhatsApp allows max 20 labels per account)
            if (str_contains($message, 'Maximum') && str_contains($message, 'labels allowed')) {
                $this->log->warning(
                    "SyncInboxMembersFromChatwoot: WAHA label limit reached for integration {$inboxIntegration->getId()} " .
                    "(session: {$inboxIntegration->get('wahaSessionName')}). Remaining memberships will not get labels."
                );
                return false;
            }

            // Detect WAHA session unavailable (HTTP 422, e.g. session deleted
            // server-side or not started). Retrying other memberships against
            // the same session is pointless within this run — bail out for
            // this inbox. Self-heals on a later run if the session comes back.
            if (str_contains($message, 'HTTP 422')) {
                $this->log->warning(
                    "SyncInboxMembersFromChatwoot: WAHA session unavailable for integration {$inboxIntegration->getId()} " .
                    "(session: {$inboxIntegration->get('wahaSessionName')}). Skipping label sync for this inbox: {$message}"
                );
                return false;
            }

            $this->log->error("SyncInboxMembersFromChatwoot: Failed to create label for membership {$membership->getId()}: " . $message);
            return true;
        }
    }

    /**
     * Delete a WAHA label for a membership-inbox combination.
     */
    private function deleteLabelForMembershipInbox(string $membershipId, Entity $inboxIntegration): void
    {
        try {
            // Find the WahaSessionLabel
            $wahaSessionLabel = $this->entityManager
                ->getRDBRepository('WahaSessionLabel')
                ->where([
                    'accountUserMembershipId' => $membershipId,
                    'inboxIntegrationId' => $inboxIntegration->getId(),
                ])
                ->findOne();

            if (!$wahaSessionLabel) {
                $this->log->debug("SyncInboxMembersFromChatwoot: No WahaSessionLabel found for membership {$membershipId}");
                return;
            }

            $wahaLabelId = $wahaSessionLabel->get('wahaLabelId');

            // Delete from WAHA
            $wahaPlatformId = $inboxIntegration->get('wahaPlatformId');

            if ($wahaLabelId && $wahaPlatformId) {
                $wahaPlatform = $this->entityManager->getEntityById(
                    'WahaPlatform',
                    $wahaPlatformId
                );

                if ($wahaPlatform) {
                    $platformUrl = $wahaPlatform->get('backendUrl');
                    $apiKey = $wahaPlatform->get('apiKey');
                    $sessionName = $inboxIntegration->get('wahaSessionName');

                    if ($platformUrl && $apiKey && $sessionName) {
                        $this->log->info("SyncInboxMembersFromChatwoot: Deleting WAHA label {$wahaLabelId} from session {$sessionName}");

                        try {
                            $this->wahaApiClient->deleteLabel(
                                $platformUrl,
                                $apiKey,
                                $sessionName,
                                $wahaLabelId
                            );
                        } catch (\Exception $e) {
                            $this->log->debug("SyncInboxMembersFromChatwoot: Failed to delete label from WAHA: " . $e->getMessage());
                        }
                    }
                }
            }

            // Delete the WahaSessionLabel record (cascadeParent skips remote API calls)
            $this->entityManager->removeEntity($wahaSessionLabel, ['cascadeParent' => true]);
            $this->log->info("SyncInboxMembersFromChatwoot: Deleted WahaSessionLabel {$wahaSessionLabel->getId()}");

        } catch (\Exception $e) {
            $this->log->error("SyncInboxMembersFromChatwoot: Failed to delete label for membership {$membershipId}: " . $e->getMessage());
        }
    }
}

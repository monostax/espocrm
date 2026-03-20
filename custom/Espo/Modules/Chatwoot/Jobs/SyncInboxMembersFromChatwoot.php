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
 * (ChatwootUser.chatwootUserId → ChatwootAccountUserMembership)
 * and writes the inbox↔membership relation as the sole source of truth.
 * WAHA labels are derived from membership-linked agents.
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
     * is resolved to a ChatwootAccountUserMembership. The inbox↔membership
     * relation is the sole relation managed. WAHA labels are derived from
     * membership-linked agents.
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
            // For each remote member, resolve to a local membership via platform user ID.
            /** @var array<string, Entity> $membershipsToLink membershipId => membership entity */
            $membershipsToLink = [];
            /** @var array<string, Entity> $agentsFromMemberships agentId => agent entity */
            $agentsFromMemberships = [];
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

                // If membership has a linked agent, load the agent entity for WAHA label operations
                $agentId = $membership->get('chatwootAgentId');

                if ($agentId) {
                    $agent = $this->entityManager->getEntityById('ChatwootAgent', $agentId);

                    if ($agent) {
                        $agentsFromMemberships[$agent->getId()] = $agent;
                    } else {
                        $this->log->debug(
                            "SyncInboxMembersFromChatwoot: Membership {$membership->getId()} has chatwootAgentId={$agentId} " .
                            "but agent entity is deleted, skipping label operations for this member"
                        );
                    }
                }
            }

            // --- Build previous agent IDs for label reconciliation ---
            // Primary path: load current accountUserMemberships on inbox → read chatwootAgentId → load agent
            $previousAgentIds = [];
            $currentMemberships = $this->entityManager
                ->getRDBRepository('ChatwootInbox')
                ->getRelation($inbox, 'accountUserMemberships')
                ->find();

            $currentMembershipIds = [];

            foreach ($currentMemberships as $m) {
                $currentMembershipIds[] = $m->getId();
                $mAgentId = $m->get('chatwootAgentId');

                if ($mAgentId) {
                    $mAgent = $this->entityManager->getEntityById('ChatwootAgent', $mAgentId);

                    if ($mAgent) {
                        $previousAgentIds[$mAgentId] = $mAgentId;
                    }
                }
            }

            // Fallback path (Decision #10 / Warning #2): also query WahaSessionLabel records
            // for this inbox integration to catch labels for agents whose membership FK is stale/deleted.
            $inboxIntegration = $this->findIntegrationForInbox($inbox);

            if ($inboxIntegration) {
                $existingLabels = $this->entityManager
                    ->getRDBRepository('WahaSessionLabel')
                    ->where(['inboxIntegrationId' => $inboxIntegration->getId()])
                    ->find();

                foreach ($existingLabels as $label) {
                    $labelAgentId = $label->get('agentId');

                    if ($labelAgentId) {
                        $previousAgentIds[$labelAgentId] = $labelAgentId;
                    }
                }
            }

            $previousAgentIds = array_values($previousAgentIds);

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

            // --- Reconcile inbox↔membership relation ---
            $membershipIdsToAdd = array_diff($desiredMembershipIds, $currentMembershipIds);
            $membershipIdsToRemove = $hasUnresolvedMembers
                ? [] // Skip removals when partially resolved
                : array_diff($currentMembershipIds, $desiredMembershipIds);

            // Add new membership links
            // Pass skipHooks to prevent SyncInboxMembership hook from pushing back to Chatwoot
            // (this data already came FROM Chatwoot).
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
            // Pass skipHooks to prevent SyncInboxMembership hook from pushing back to Chatwoot
            // (this data already came FROM Chatwoot).
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

            // --- WAHA label lifecycle (derived from membership-linked agents) ---
            if ($inboxIntegration) {
                $desiredAgentIds = array_keys($agentsFromMemberships);

                // Added agents: create labels
                $addedAgentIds = array_diff($desiredAgentIds, $previousAgentIds);

                foreach ($addedAgentIds as $agentId) {
                    if (isset($agentsFromMemberships[$agentId])) {
                        $this->createLabelForAgentInbox($agentsFromMemberships[$agentId], $inboxIntegration);
                    }
                }

                // Removed agents: delete labels
                $removedAgentIds = array_diff($previousAgentIds, $desiredAgentIds);

                foreach ($removedAgentIds as $agentId) {
                    $this->deleteLabelForAgentInbox($agentId, $inboxIntegration);
                }

                // Reconcile labels for all desired agents (create missing labels)
                foreach ($agentsFromMemberships as $agent) {
                    $this->reconcileLabelForAgentInbox($agent, $inboxIntegration);
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
     * Reconcile label for an existing agent-inbox combination (create if missing).
     */
    private function reconcileLabelForAgentInbox(Entity $agent, Entity $inboxIntegration): void
    {
        // Check if label already exists
        $existingLabel = $this->entityManager
            ->getRDBRepository('WahaSessionLabel')
            ->where([
                'agentId' => $agent->getId(),
                'inboxIntegrationId' => $inboxIntegration->getId(),
            ])
            ->findOne();

        if (!$existingLabel) {
            // Label doesn't exist, create it
            $this->createLabelForAgentInbox($agent, $inboxIntegration);
        }
    }

    /**
     * Create a WAHA label for an agent-inbox combination.
     */
    private function createLabelForAgentInbox(Entity $agent, Entity $inboxIntegration): void
    {
        try {
            // Check if label already exists
            $existingLabel = $this->entityManager
                ->getRDBRepository('WahaSessionLabel')
                ->where([
                    'agentId' => $agent->getId(),
                    'inboxIntegrationId' => $inboxIntegration->getId(),
                ])
                ->findOne();

            if ($existingLabel) {
                $this->log->debug("SyncInboxMembersFromChatwoot: Label already exists for agent {$agent->getId()} + integration {$inboxIntegration->getId()}");
                return;
            }

            // Get WAHA platform and session info
            $wahaPlatformId = $inboxIntegration->get('wahaPlatformId');

            if (!$wahaPlatformId) {
                $this->log->debug("SyncInboxMembersFromChatwoot: No wahaPlatformId for integration {$inboxIntegration->getId()}, skipping label creation");
                return;
            }

            $wahaPlatform = $this->entityManager->getEntityById(
                'WahaPlatform',
                $wahaPlatformId
            );

            if (!$wahaPlatform) {
                $this->log->debug("SyncInboxMembersFromChatwoot: WahaPlatform not found for integration {$inboxIntegration->getId()}");
                return;
            }

            $platformUrl = $wahaPlatform->get('backendUrl');
            $apiKey = $wahaPlatform->get('apiKey');
            $sessionName = $inboxIntegration->get('wahaSessionName');

            if (!$platformUrl || !$apiKey || !$sessionName) {
                $this->log->debug("SyncInboxMembersFromChatwoot: Missing WAHA credentials or session name");
                return;
            }

            // Generate label name and color based on agent type
            $labelPrefix = $agent->get('isAI') ? '[✨]' : '[👤]';
            $labelName = $labelPrefix . ' ' . $agent->get('name');
            $color = abs(crc32($agent->getId())) % 20;
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
                return;
            }

            // Create WahaSessionLabel record
            $this->entityManager->createEntity('WahaSessionLabel', [
                'name' => $labelName,
                'wahaLabelId' => (string) $wahaLabelId,
                'color' => $color,
                'colorHex' => $wahaResponse['colorHex'] ?? $colorHex,
                'agentId' => $agent->getId(),
                'inboxIntegrationId' => $inboxIntegration->getId(),
                'teamsIds' => $inboxIntegration->getLinkMultipleIdList('teams'),
                'syncStatus' => 'synced',
            ], ['silent' => true]);

            $this->log->info("SyncInboxMembersFromChatwoot: Created WahaSessionLabel for agent {$agent->getId()} with WAHA ID {$wahaLabelId}");

        } catch (\Exception $e) {
            $this->log->error("SyncInboxMembersFromChatwoot: Failed to create label for agent {$agent->getId()}: " . $e->getMessage());
        }
    }

    /**
     * Delete a WAHA label for an agent-inbox combination.
     */
    private function deleteLabelForAgentInbox(string $agentId, Entity $inboxIntegration): void
    {
        try {
            // Find the WahaSessionLabel
            $wahaSessionLabel = $this->entityManager
                ->getRDBRepository('WahaSessionLabel')
                ->where([
                    'agentId' => $agentId,
                    'inboxIntegrationId' => $inboxIntegration->getId(),
                ])
                ->findOne();

            if (!$wahaSessionLabel) {
                $this->log->debug("SyncInboxMembersFromChatwoot: No WahaSessionLabel found for agent {$agentId}");
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
            $this->log->error("SyncInboxMembersFromChatwoot: Failed to delete label for agent {$agentId}: " . $e->getMessage());
        }
    }
}

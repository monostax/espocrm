<?php
/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 *
 * This software and associated documentation files (the "Software") are
 * the proprietary and confidential information of Monostax.
 *
 * Unauthorized copying, distribution, modification, public display, or use
 * of this Software, in whole or in part, via any medium, is strictly
 * prohibited without the express prior written permission of Monostax.
 *
 * This Software is licensed, not sold. Commercial use of this Software
 * requires a valid license from Monostax.
 *
 * For licensing information, please visit: https://www.monostax.ai
 ************************************************************************/

namespace Espo\Modules\Chatwoot\Rebuild;

use Espo\Core\Rebuild\RebuildAction;
use Espo\Core\Utils\Log;
use Espo\ORM\EntityManager;
use Espo\Modules\Chatwoot\Services\ChatwootAccountUserMembershipService;

/**
 * RebuildAction that migrates data from the old ChatwootAgent entity
 * to ChatwootAccountUserMembership records.
 *
 * Runs during `php rebuild`. Idempotent across repeated runs.
 *
 * Three sequential passes:
 *   Pass 1 — Copy unique agent fields to corresponding memberships + migrate junction tables
 *   Pass 2 — Log orphan agents (agents without a matching membership)
 *   Pass 3 — Retired stub (inbox links now managed by SyncInboxMembersFromChatwoot)
 */
class BackfillAccountUserMemberships implements RebuildAction
{
    public function __construct(
        private EntityManager $entityManager,
        private Log $log,
        private ChatwootAccountUserMembershipService $membershipService
    ) {}

    public function process(): void
    {
        $this->log->info('BackfillAccountUserMemberships: Starting backfill');

        $this->pass1BackfillFromLinkedAgents();
        $this->pass2RepairOrphanAgents();
        $this->pass3BackfillInboxLinks();

        $this->log->info('BackfillAccountUserMemberships: Backfill complete');
    }

    /**
     * Pass 1 — Copy unique agent fields to corresponding memberships.
     *
     * For each ChatwootAgent with a chatwootUserId, find the corresponding
     * membership by (chatwootAccountId, chatwootUserId) and copy over all
     * unique fields that now live on the membership.
     *
     * Also migrates junction table rows and WahaSessionLabel FK references
     * via raw SQL.
     */
    private function pass1BackfillFromLinkedAgents(): void
    {
        // Check if ChatwootAgent table still exists (may already be dropped)
        $pdo = $this->entityManager->getPDO();

        try {
            $checkStmt = $pdo->query("SELECT 1 FROM chatwoot_agent LIMIT 1");
        } catch (\Exception $e) {
            $this->log->info(
                'BackfillAccountUserMemberships: Pass 1 — ChatwootAgent table does not exist, skipping migration'
            );
            return;
        }

        // Use raw SQL since ChatwootAgent entity metadata no longer exists.
        // The chatwoot_agent table may still exist in the DB from before the migration.
        $agentRows = $pdo->query("
            SELECT id, chatwoot_account_id, chatwoot_user_id, role,
                   available_name, availability_status, auto_offline, confirmed,
                   avatar_url, custom_role_id, is_a_i, ai_prompt,
                   ignore_groups, ignore_status_broadcast,
                   contextual_transfer_is_enabled, transfer_scenarios
            FROM chatwoot_agent
            WHERE chatwoot_user_id IS NOT NULL
              AND chatwoot_user_id != ''
              AND chatwoot_account_id IS NOT NULL
        ")->fetchAll(\PDO::FETCH_ASSOC);

        $processed = 0;
        $errors = 0;

        foreach ($agentRows as $agent) {
            try {
                $accountId = $agent['chatwoot_account_id'];
                $userId = $agent['chatwoot_user_id'];

                if (!$accountId || !$userId) {
                    continue;
                }

                // Find the corresponding membership
                $membership = $this->entityManager
                    ->getRDBRepository('ChatwootAccountUserMembership')
                    ->where([
                        'chatwootAccountId' => $accountId,
                        'chatwootUserId' => $userId,
                    ])
                    ->findOne();

                if (!$membership) {
                    // Create membership first
                    $role = $agent['role'] ?? 'agent';
                    $membership = $this->membershipService->upsertMembership(
                        $accountId,
                        $userId,
                        $role
                    );
                }

                // Copy unique fields from agent to membership
                // Note: is_a_i is EspoCRM's snake_case for isAI; email does not exist on chatwoot_agent
                $membership->set('isAI', (bool)($agent['is_a_i'] ?? false));
                $membership->set('aiPrompt', $agent['ai_prompt']);
                $membership->set('ignoreGroups', (bool)($agent['ignore_groups'] ?? false));
                $membership->set('ignoreStatusBroadcast', (bool)($agent['ignore_status_broadcast'] ?? false));
                $membership->set('contextualTransferIsEnabled', (bool)($agent['contextual_transfer_is_enabled'] ?? false));
                $membership->set('transferScenarios', $agent['transfer_scenarios']);
                $membership->set('availableName', $agent['available_name']);
                $membership->set('availabilityStatus', $agent['availability_status']);
                $membership->set('autoOffline', (bool)($agent['auto_offline'] ?? true));
                $membership->set('confirmed', (bool)($agent['confirmed'] ?? false));
                $membership->set('avatarUrl', $agent['avatar_url']);
                $membership->set('customRoleId', $agent['custom_role_id'] ? (int)$agent['custom_role_id'] : null);

                $this->entityManager->saveEntity($membership, ['silent' => true]);

                $processed++;
            } catch (\Throwable $e) {
                $errors++;
                $this->log->warning(
                    "BackfillAccountUserMemberships: Pass 1 error for agent {$agent['id']}: " .
                    $e->getMessage()
                );
            }
        }

        // Migrate junction table rows via raw SQL
        $this->migrateJunctionTables($pdo);

        // Remap WahaSessionLabel FK
        $this->remapWahaSessionLabels($pdo);

        $this->log->info(
            "BackfillAccountUserMemberships: Pass 1 complete — processed={$processed} errors={$errors}"
        );
    }

    /**
     * Migrate junction table rows from agent-based to membership-based.
     *
     * Maps: chatwoot_agent_id → membership ID via the agent→membership FK.
     */
    private function migrateJunctionTables(\PDO $pdo): void
    {
        $junctionMappings = [
            [
                'source' => 'chatwoot_agent_chatwoot_team',
                'target' => 'chatwoot_account_user_membership_chatwoot_team',
                'sourceFK' => 'chatwoot_agent_id',
                'targetFK' => 'chatwoot_account_user_membership_id',
                'otherFK' => 'chatwoot_team_id',
            ],
            [
                'source' => 'chatwoot_agent_knowledge_base_category',
                'target' => 'chatwoot_account_user_membership_knowledge_base_category',
                'sourceFK' => 'chatwoot_agent_id',
                'targetFK' => 'chatwoot_account_user_membership_id',
                'otherFK' => 'knowledge_base_category_id',
            ],
            [
                'source' => 'chatwoot_agent_calendar_user',
                'target' => 'chatwoot_account_user_membership_calendar_user',
                'sourceFK' => 'chatwoot_agent_id',
                'targetFK' => 'chatwoot_account_user_membership_id',
                'otherFK' => 'user_id',
            ],
        ];

        foreach ($junctionMappings as $mapping) {
            try {
                // Check if source table exists
                try {
                    $pdo->query("SELECT 1 FROM {$mapping['source']} LIMIT 1");
                } catch (\Exception $e) {
                    $this->log->debug(
                        "BackfillAccountUserMemberships: Source table {$mapping['source']} does not exist, skipping"
                    );
                    continue;
                }

                // Check if target table exists
                try {
                    $pdo->query("SELECT 1 FROM {$mapping['target']} LIMIT 1");
                } catch (\Exception $e) {
                    $this->log->debug(
                        "BackfillAccountUserMemberships: Target table {$mapping['target']} does not exist, skipping"
                    );
                    continue;
                }

                // Build the agent→membership resolution subquery
                // Agents have chatwoot_account_id and chatwoot_user_id; memberships match on those.
                $sql = "
                    INSERT IGNORE INTO {$mapping['target']} ({$mapping['targetFK']}, {$mapping['otherFK']}, deleted)
                    SELECT m.id, j.{$mapping['otherFK']}, j.deleted
                    FROM {$mapping['source']} j
                    INNER JOIN chatwoot_agent a ON a.id = j.{$mapping['sourceFK']}
                    INNER JOIN chatwoot_account_user_membership m
                        ON m.chatwoot_account_id = a.chatwoot_account_id
                        AND m.chatwoot_user_id = a.chatwoot_user_id
                    WHERE a.chatwoot_user_id IS NOT NULL
                ";

                $count = $pdo->exec($sql);

                $this->log->info(
                    "BackfillAccountUserMemberships: Migrated {$count} row(s) from {$mapping['source']} to {$mapping['target']}"
                );
            } catch (\Exception $e) {
                $this->log->warning(
                    "BackfillAccountUserMemberships: Failed to migrate {$mapping['source']}: " .
                    $e->getMessage()
                );
            }
        }
    }

    /**
     * Remap WahaSessionLabel FK from agent_id to account_user_membership_id.
     */
    private function remapWahaSessionLabels(\PDO $pdo): void
    {
        try {
            // Check if the old column still exists
            try {
                $pdo->query("SELECT agent_id FROM waha_session_label LIMIT 1");
            } catch (\Exception $e) {
                $this->log->debug(
                    'BackfillAccountUserMemberships: waha_session_label.agent_id column does not exist, skipping remap'
                );
                return;
            }

            // Check if the new column exists
            try {
                $pdo->query("SELECT account_user_membership_id FROM waha_session_label LIMIT 1");
            } catch (\Exception $e) {
                $this->log->debug(
                    'BackfillAccountUserMemberships: waha_session_label.account_user_membership_id column does not exist, skipping remap'
                );
                return;
            }

            $sql = "
                UPDATE waha_session_label wsl
                INNER JOIN chatwoot_agent a ON a.id = wsl.agent_id
                INNER JOIN chatwoot_account_user_membership m
                    ON m.chatwoot_account_id = a.chatwoot_account_id
                    AND m.chatwoot_user_id = a.chatwoot_user_id
                SET wsl.account_user_membership_id = m.id
                WHERE wsl.agent_id IS NOT NULL
                  AND wsl.account_user_membership_id IS NULL
                  AND a.chatwoot_user_id IS NOT NULL
            ";

            $count = $pdo->exec($sql);

            $this->log->info(
                "BackfillAccountUserMemberships: Remapped {$count} WahaSessionLabel row(s) from agent_id to account_user_membership_id"
            );
        } catch (\Exception $e) {
            $this->log->warning(
                'BackfillAccountUserMemberships: Failed to remap WahaSessionLabel FKs: ' .
                $e->getMessage()
            );
        }
    }

    /**
     * Pass 2 — Log orphan agents (agents without a user link).
     *
     * These agents cannot be migrated to memberships because memberships
     * require a chatwootUserId. Just log them for manual review.
     */
    private function pass2RepairOrphanAgents(): void
    {
        // Check if ChatwootAgent table still exists
        try {
            $this->entityManager->getPDO()->query("SELECT 1 FROM chatwoot_agent LIMIT 1");
        } catch (\Exception $e) {
            $this->log->info(
                'BackfillAccountUserMemberships: Pass 2 — ChatwootAgent table does not exist, skipping'
            );
            return;
        }

        // Use raw SQL since ChatwootAgent entity metadata no longer exists.
        $orphanRows = $this->entityManager->getPDO()->query("
            SELECT id, name, chatwoot_account_id
            FROM chatwoot_agent
            WHERE (chatwoot_user_id IS NULL OR chatwoot_user_id = '')
              AND chatwoot_account_id IS NOT NULL
              AND deleted = 0
        ")->fetchAll(\PDO::FETCH_ASSOC);

        $orphanCount = 0;

        foreach ($orphanRows as $agent) {
            $orphanCount++;
            $this->log->warning(
                "BackfillAccountUserMemberships: Pass 2 — orphan agent {$agent['id']} " .
                "(name={$agent['name']}, accountId={$agent['chatwoot_account_id']}) " .
                "has no chatwootUserId — cannot migrate to membership"
            );
        }

        $this->log->info(
            "BackfillAccountUserMemberships: Pass 2 complete — orphanAgents={$orphanCount}"
        );
    }

    /**
     * Pass 3 — Retired: inbox↔membership links now managed by SyncInboxMembersFromChatwoot.
     */
    private function pass3BackfillInboxLinks(): void
    {
        $this->log->info('BackfillAccountUserMemberships: Pass 3 retired — inbox membership links now managed by SyncInboxMembersFromChatwoot');
    }
}

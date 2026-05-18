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

namespace Espo\Modules\Global\Rebuild;

use Espo\Core\Rebuild\RebuildAction;
use Espo\Core\Utils\Log;
use Espo\ORM\EntityManager;
use Throwable;

/**
 * Phase F.1 backfill — Opportunity.followup_ai_agent_id provenance.
 *
 * Stamps `followup_ai_agent_id` on every `FollowupActive` row whose stamp
 * is currently NULL. The stamped value mirrors the legacy
 * `getAIAgentForInbox(…) ?? getAIAgentForAccount(…)` tie-break used by
 * `helpers.ts:64-158` (oldest-by-`created_at`, PK fallback). See
 * `plan-followup-active-collapse.md` Phase F.
 *
 * Pre-condition (Phase B): the `followup_ai_agent_id` column exists.
 * If the column is missing (column-readiness guard via `try/catch`),
 * the action skips silently.
 *
 * Why this exists:
 *   Phase F.2 of the backend deploy enforces strict semantics in
 *   `Task.FetchAgentConfig` — NULL `followupAiAgentId` cancels the
 *   follow-up run (decision F1). Without this backfill, every existing
 *   `FollowupActive` row created before Phase D would lose its
 *   follow-up loop on first deploy. This action populates the column
 *   ONCE for migration-state rows; subsequent NULL stamps will be
 *   from rows where no AI agent exists on the account at all
 *   (correctly cancelled — see goal §1).
 *
 * Why the SQL is fat:
 *   Mirrors the two-step lookup in `helpers.ts:getAIAgentForInbox` →
 *   `getAIAgentForAccount`. The opportunity row doesn't directly carry
 *   a chatwootAccount/inbox FK; resolve via the join chain
 *     opportunity → chatwoot_conversation_opportunity →
 *     chatwoot_conversation → chatwoot_inbox → chatwoot_account.
 *   The two `ROW_NUMBER()` CTEs reproduce the
 *   `ORDER BY created_at ASC, id ASC LIMIT 1` tie-break verbatim.
 *
 * Idempotency:
 *   The `WHERE followup_ai_agent_id IS NULL` clause makes re-runs
 *   no-ops. Safe to invoke on every `php bin/command rebuild`.
 *
 * Orphan accounts (no AI agent at all):
 *   Rows on those accounts remain NULL after this backfill. The
 *   COALESCE filter at the bottom of the UPDATE prevents NULL writes.
 *   A separate INFO log surfaces the count so ops knows how many rows
 *   Phase F.2 will cancel. These would have been broken under either
 *   the old or the new code path — the only difference is that under
 *   the old code the cancellation was silent.
 */
class BackfillOpportunityFollowupAiAgentId implements RebuildAction
{
    public function __construct(
        private EntityManager $entityManager,
        private Log $log,
    ) {}

    public function process(): void
    {
        $this->log->info('Global Module: Starting Opportunity followup_ai_agent_id backfill...');

        $pdo = $this->entityManager->getPDO();

        try {
            $countStmt = $pdo->query(
                "SELECT COUNT(*) FROM `opportunity` "
                . "WHERE `followup_status` = 'FollowupActive' "
                . "AND `followup_ai_agent_id` IS NULL "
                . "AND `deleted` = 0"
            );
            $remaining = (int) $countStmt->fetchColumn();
        } catch (Throwable $e) {
            // Column may not yet exist on a fresh install before rebuild
            // applies the Phase B schema diff. Skip silently — there is
            // nothing safe to backfill until the column lands.
            $this->log->info(
                'Global Module: Opportunity.followup_ai_agent_id column not ready for '
                . 'provenance backfill; skipping. ' . $e->getMessage()
            );

            return;
        }

        if ($remaining === 0) {
            $this->log->info(
                'Global Module: No Opportunities need followup_ai_agent_id backfill.'
            );

            return;
        }

        $this->log->info(
            "Global Module: {$remaining} Opportunity(ies) need followup_ai_agent_id backfill."
        );

        try {
            // Raw UPDATE; skips the Espo Hook layer (pure data migration).
            // ROW_NUMBER() requires MariaDB 10.4.3+ for UPDATE-with-CTE
            // support; production verified at 12.0.2.
            //
            // The two CTEs mirror getAIAgentForInbox / getAIAgentForAccount
            // tie-break: ORDER BY created_at ASC, id ASC; LIMIT 1 per
            // (account, inbox) and per (account) respectively.
            //
            // The trailing COALESCE-IS-NOT-NULL filter ensures we never
            // write a NULL — rows whose account has no AI agent at all
            // are left untouched and surface in the post-UPDATE INFO log
            // below so Phase F.2's strict cancellation isn't surprising.
            $stmt = $pdo->prepare(
                "UPDATE `opportunity` o "
                . "JOIN `chatwoot_conversation_opportunity` cco "
                .   "ON cco.opportunity_id = o.id AND cco.deleted = 0 "
                . "JOIN `chatwoot_conversation` cc "
                .   "ON cc.id = cco.chatwoot_conversation_id AND cc.deleted = 0 "
                . "JOIN `chatwoot_inbox` ci "
                .   "ON ci.chatwoot_inbox_id = cc.chatwoot_inbox_id AND ci.deleted = 0 "
                . "JOIN `chatwoot_account` ca "
                .   "ON ca.id = ci.chatwoot_account_id AND ca.deleted = 0 "
                . "LEFT JOIN ("
                .   "SELECT "
                .     "m.id AS membership_id, "
                .     "m.chatwoot_account_id, "
                .     "cii.chatwoot_inbox_id AS inbox_platform_id, "
                .     "ROW_NUMBER() OVER ("
                .       "PARTITION BY m.chatwoot_account_id, cii.chatwoot_inbox_id "
                .       "ORDER BY m.created_at ASC, m.id ASC"
                .     ") AS rn "
                .   "FROM `chatwoot_account_user_membership` m "
                .   "JOIN `chatwoot_user` u "
                .     "ON m.chatwoot_user_id = u.id AND u.deleted = 0 "
                .   "JOIN `chatwoot_account_user_membership_chatwoot_inbox` mi "
                .     "ON mi.chatwoot_account_user_membership_id = m.id AND mi.deleted = 0 "
                .   "JOIN `chatwoot_inbox` cii "
                .     "ON mi.chatwoot_inbox_id = cii.id AND cii.deleted = 0 "
                .   "WHERE m.is_a_i = 1 AND m.deleted = 0"
                . ") inbox_agent "
                .   "ON inbox_agent.chatwoot_account_id = ca.id "
                .   "AND inbox_agent.inbox_platform_id = ci.chatwoot_inbox_id "
                .   "AND inbox_agent.rn = 1 "
                . "LEFT JOIN ("
                .   "SELECT "
                .     "m.id AS membership_id, "
                .     "m.chatwoot_account_id, "
                .     "ROW_NUMBER() OVER ("
                .       "PARTITION BY m.chatwoot_account_id "
                .       "ORDER BY m.created_at ASC, m.id ASC"
                .     ") AS rn "
                .   "FROM `chatwoot_account_user_membership` m "
                .   "JOIN `chatwoot_user` u "
                .     "ON m.chatwoot_user_id = u.id AND u.deleted = 0 "
                .   "WHERE m.is_a_i = 1 AND m.deleted = 0"
                . ") account_agent "
                .   "ON account_agent.chatwoot_account_id = ca.id "
                .   "AND account_agent.rn = 1 "
                . "SET o.followup_ai_agent_id = COALESCE("
                .   "inbox_agent.membership_id, account_agent.membership_id"
                . ") "
                . "WHERE o.followup_status = 'FollowupActive' "
                . "AND o.followup_ai_agent_id IS NULL "
                . "AND o.deleted = 0 "
                . "AND COALESCE(inbox_agent.membership_id, account_agent.membership_id) IS NOT NULL"
            );
            $stmt->execute();

            $updated = $stmt->rowCount();

            $this->log->info(
                "Global Module: Opportunity followup_ai_agent_id backfill complete. "
                . "Updated: {$updated}."
            );

            // Surface orphan-account rows (FollowupActive + still-NULL
            // stamp after the UPDATE). These have no AI agent on their
            // account and will be cancelled by Phase F.2's strict
            // semantics on first dispatch.
            try {
                $orphanStmt = $pdo->query(
                    "SELECT COUNT(*) FROM `opportunity` "
                    . "WHERE `followup_status` = 'FollowupActive' "
                    . "AND `followup_ai_agent_id` IS NULL "
                    . "AND `deleted` = 0"
                );
                $orphans = (int) $orphanStmt->fetchColumn();

                if ($orphans > 0) {
                    $this->log->warning(
                        "Global Module: {$orphans} Opportunity(ies) remain in "
                        . "FollowupActive with NULL followup_ai_agent_id after backfill "
                        . "(no AI agent on the account). These will be cancelled by "
                        . "Phase F.2's strict cancellation on first dispatch."
                    );
                }
            } catch (Throwable $e) {
                // Non-fatal; the UPDATE already succeeded.
                $this->log->info(
                    'Global Module: Post-backfill orphan-count probe failed: ' . $e->getMessage()
                );
            }
        } catch (Throwable $e) {
            $this->log->error(
                'Global Module: Opportunity followup_ai_agent_id backfill failed: '
                . $e->getMessage()
            );
        }
    }
}

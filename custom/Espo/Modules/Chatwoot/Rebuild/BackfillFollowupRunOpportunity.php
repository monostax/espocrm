<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Rebuild;

use Espo\Core\Rebuild\RebuildAction;
use Espo\Core\Utils\Log;
use Espo\ORM\EntityManager;

/**
 * Follow-up history repair, idempotent on every rebuild.
 *
 * 1. Links each `followup-trigger` run to its opportunity. The backend now
 *    stamps it; older runs only carry the conversation. A follow-up only fires
 *    for the conversation's active opportunity, which is the latest one of the
 *    same tenant created before the run. Runs with no such opportunity (sent
 *    before opportunities were created systematically) stay unlinked.
 * 2. Raises `followupLifetimeCount` to the number of sent follow-ups for
 *    opportunities created before the live counter existed. Its initial value
 *    was copied from the per-cycle count, which a customer reply resets to 0,
 *    so exactly the follow-ups that got a reply were missing. Later
 *    opportunities are counted live and are left untouched. Version and
 *    modification stamps are not bumped: no follow-up state changes.
 */
class BackfillFollowupRunOpportunity implements RebuildAction
{
    /** First full UTC day with the live lifetime counter (commit of 2026-10-13). */
    private const LIVE_COUNTER_SINCE = '2026-10-15 00:00:00';

    private const SENT_RUNS = "FROM chatwoot_ai_agent_run r WHERE r.kind = 'followup-trigger' "
        . 'AND r.sent_followup_message = TRUE AND r.deleted = FALSE';

    public function __construct(
        private EntityManager $entityManager,
        private Log $log,
    ) {}

    public function process(): void
    {
        $pdo = $this->entityManager->getPDO();

        $candidates = 'FROM chatwoot_conversation_opportunity co '
            . 'JOIN opportunity o ON o.id = co.opportunity_id AND o.deleted = FALSE '
            . 'WHERE co.chatwoot_conversation_id = chatwoot_ai_agent_run.conversation_id AND co.deleted = FALSE '
            . 'AND o.tenant_id = chatwoot_ai_agent_run.tenant_id AND o.created_at <= chatwoot_ai_agent_run.run_at';
        $linked = $pdo->exec(
            "UPDATE chatwoot_ai_agent_run SET opportunity_id = (SELECT o.id $candidates "
            . 'ORDER BY o.created_at DESC, o.id DESC LIMIT 1) '
            . "WHERE kind = 'followup-trigger' AND opportunity_id IS NULL AND conversation_id IS NOT NULL "
            . "AND tenant_id IS NOT NULL AND deleted = FALSE AND EXISTS (SELECT 1 $candidates)"
        );

        $sent = '(SELECT COUNT(*) ' . self::SENT_RUNS . ' AND r.opportunity_id = opportunity.id)';
        $counted = $pdo->prepare(
            "UPDATE opportunity SET followup_lifetime_count = $sent "
            . 'WHERE deleted = FALSE AND created_at < :since '
            . 'AND id IN (SELECT r.opportunity_id ' . self::SENT_RUNS . ' AND r.opportunity_id IS NOT NULL) '
            . "AND COALESCE(followup_lifetime_count, 0) < $sent"
        );
        $counted->execute(['since' => self::LIVE_COUNTER_SINCE]);

        if ($linked || $counted->rowCount()) {
            $this->log->info('Chatwoot Module: follow-up history backfill linked {linked} runs, recounted {counted} opportunities.', [
                'linked' => $linked,
                'counted' => $counted->rowCount(),
            ]);
        }
    }
}

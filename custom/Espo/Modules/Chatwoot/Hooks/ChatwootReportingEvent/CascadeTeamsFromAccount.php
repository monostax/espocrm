<?php

namespace Espo\Modules\Chatwoot\Hooks\ChatwootReportingEvent;

use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Auto-cascades teams from the parent ChatwootAccount onto this row's
 * `teams` linkMultiple. Espo enforces multi-tenant ACL via team
 * membership for Chatwoot entities (the team set IS the tenant
 * boundary), so without this hook a non-admin user would see zero
 * rows even if they own the parent account.
 *
 * Mirrors `ChatwootAiAgentRun/CascadeTeamsFromAccount.php` verbatim;
 * the only thing that differs across these per-entity hooks is the
 * namespace. The logic is generic because every entity that uses it
 * has a `chatwootAccountId` foreign key.
 *
 * Note on coverage: the production write path for ChatwootReportingEvent
 * is the Hatchet cron sync workflow + one-shot backfill, both of which
 * write directly via Drizzle and therefore BYPASS this hook. The same
 * cascade is replicated in
 * `drizzle.crm.app/helpers.ts::cascadeTeamsFromAccount` so the
 * `entity_team` rows are seeded inside that same insert path. This PHP
 * hook still fires for any Espo-side write (admin UI duplicate / REST
 * POST), which keeps the invariant intact across both write paths.
 *
 * Order=1 so the team set is in place before any validation hooks run.
 */
class CascadeTeamsFromAccount
{
    public static int $order = 1;

    public function __construct(
        private EntityManager $entityManager
    ) {}

    public function beforeSave(Entity $entity, array $options): void
    {
        if (!empty($options['silent'])) {
            return;
        }

        $accountId = $entity->get('chatwootAccountId');
        if (!$accountId) {
            return;
        }

        $account = $this->entityManager->getEntityById('ChatwootAccount', $accountId);
        if (!$account) {
            return;
        }

        $teamsIds = $account->getLinkMultipleIdList('teams');
        if (!empty($teamsIds)) {
            $entity->set('teamsIds', $teamsIds);
        }
    }
}

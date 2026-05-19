<?php

namespace Espo\Modules\Chatwoot\Hooks\ChatwootAiAgentRun;

use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Auto-cascades teams from the parent ChatwootAccount onto this row's
 * `teams` linkMultiple. Espo enforces multi-tenant ACL via team
 * membership for Chatwoot entities — there is intentionally no
 * `tenantId` column on this entity (same convention as ChatwootMessage,
 * ChatwootConversation, etc.), so the team set IS the tenant boundary.
 *
 * Mirrors `ChatwootMessage/CascadeTeamsFromAccount.php` and
 * `ChatwootConversation/CascadeTeamsFromAccount.php` verbatim — the
 * only thing that differs across these three hooks is the namespace.
 * The logic is generic because every entity that uses it has a
 * `chatwootAccountId` foreign key.
 *
 * Note on coverage: the production write path for ChatwootAiAgentRun is
 * the Hatchet consumer workflow (`workflows/$chatwootAgentRunPersist.ts`
 * in the backend), which writes directly via Drizzle and therefore
 * BYPASSES this hook. The same cascade is replicated in
 * `drizzle.crm.app/helpers.ts::cascadeTeamsFromAccount` so the
 * `entity_team` rows are seeded inside that same insert path. This PHP
 * hook still fires whenever a row is created through Espo's ORM (admin
 * UI duplicate / REST POST / future Espo-side seeders), which keeps the
 * invariant intact across both write paths.
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
        // Skip for sync jobs - they handle team assignment directly
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

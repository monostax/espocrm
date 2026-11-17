<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCatchUp\Rebuild;

use Espo\Core\Rebuild\RebuildAction;
use Espo\Modules\FeatureCatchUp\Services\ConversationCycle;
use Espo\ORM\EntityManager;

/** Clean up opportunities which were closed or removed before the discard hook was installed. */
class DiscardObsoleteDealCards implements RebuildAction
{
    public function __construct(private EntityManager $em, private ConversationCycle $cycle) {}

    public function process(): void
    {
        $pdo = $this->em->getPDO();
        $pdo->exec("DELETE FROM catch_up_verdict WHERE opportunity_id IS NOT NULL AND NOT EXISTS (
            SELECT 1 FROM opportunity o WHERE o.id = catch_up_verdict.opportunity_id AND o.deleted = 0 AND o.status NOT IN ('Won','Lost')
        )");
        // Espo stamps modified_at when it soft-deletes a record. Use the actual loss/deletion time,
        // not rebuild time: customers who subsequently wrote again already started a new cycle.
        $rows = $pdo->query("SELECT c.id, MAX(CASE WHEN o.deleted = 1 THEN o.modified_at
                ELSE COALESCE(o.stage_entered_at, o.modified_at, o.created_at) END) AS cutoff
            FROM chatwoot_conversation_opportunity r JOIN opportunity o ON o.id = r.opportunity_id
            JOIN chatwoot_conversation c ON c.id = r.chatwoot_conversation_id AND c.deleted = 0
            JOIN chatwoot_account a ON a.id = c.chatwoot_account_id AND a.tenant_id = o.tenant_id
            WHERE r.deleted = 0 AND (o.deleted = 1 OR o.status = 'Lost')
            GROUP BY c.id, c.catch_up_discarded_at HAVING cutoff > COALESCE(c.catch_up_discarded_at, '1970-01-01')");
        foreach ($rows->fetchAll(\PDO::FETCH_NUM) as [$id, $cutoff]) $this->cycle->discardThrough($id, $cutoff);
    }
}

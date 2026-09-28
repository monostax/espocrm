<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureAiUsage\Attribution;

use PDO;

/** Missing links are repairable; explicit waivers are immutable to reconciliation. */
class Reconciler
{
    public function reconcile(PDO $pdo): int
    {
        // Select only resolvable candidates so missing mirrors cannot starve later runs.
        // The CRM enforces uniqueness of account + conversation display ID + delete ID.
        $rows = $pdo->query("SELECT r.id, r.chatwoot_account_id, r.tenant_id,
                r.source_conversation_id, c.id AS target_id
            FROM chatwoot_ai_agent_run r
            INNER JOIN chatwoot_account a ON a.id = r.chatwoot_account_id
                AND a.tenant_id = r.tenant_id AND a.deleted = false
            INNER JOIN chatwoot_conversation c ON c.chatwoot_account_id = a.id
                AND c.chatwoot_conversation_id = r.source_conversation_id AND c.deleted = false
            WHERE r.deleted = false AND r.conversation_id IS NULL
                AND r.kind <> 'opportunity-mention'
            ORDER BY r.id LIMIT 500")->fetchAll(PDO::FETCH_ASSOC);

        $update = $pdo->prepare("UPDATE chatwoot_ai_agent_run SET conversation_id = ?, modified_at = ?
            WHERE id = ? AND chatwoot_account_id = ? AND tenant_id = ?
                AND source_conversation_id = ? AND conversation_id IS NULL AND deleted = false
                AND kind <> 'opportunity-mention'
                AND EXISTS (SELECT 1 FROM chatwoot_conversation c
                    INNER JOIN chatwoot_account a ON a.id = c.chatwoot_account_id
                    WHERE c.id = ? AND c.chatwoot_account_id = ? AND c.chatwoot_conversation_id = ?
                        AND a.tenant_id = ? AND c.deleted = false AND a.deleted = false)");
        $count = 0;
        foreach ($rows as $row) {
            $update->execute([
                $row['target_id'], gmdate('Y-m-d H:i:s'), $row['id'], $row['chatwoot_account_id'],
                $row['tenant_id'], $row['source_conversation_id'], $row['target_id'],
                $row['chatwoot_account_id'], $row['source_conversation_id'], $row['tenant_id'],
            ]);
            $count += $update->rowCount();
        }
        return $count;
    }
}

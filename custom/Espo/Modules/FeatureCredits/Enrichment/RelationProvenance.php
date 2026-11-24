<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Enrichment;

use Espo\Modules\FeatureRecordKnowledge\Services\Relations;
use PDO;

/**
 * Marks the relation records that applying a purchased sócio/employee created as `via = enrichment`, for links made
 * before provenance was stored. Exact, never a guess: the receipt names the relation records the application touched,
 * and a record counts only when its submission key is the one the application itself would have produced
 * (sha256(actor:enrichment:<purchase>:<index>)), so a pre-existing manual link the application merely reused keeps no
 * provenance. Idempotent.
 */
final class RelationProvenance
{
    /** CandidateApplication::relationships yields at most a few predicates per sócio. */
    private const MAX_INDEX = 16;

    public static function backfill(PDO $pdo): int
    {
        $rows = $pdo->query('SELECT id, tenant_id, application_receipt FROM enrichment_purchase
            WHERE application_receipt IS NOT NULL AND deleted = FALSE')->fetchAll(PDO::FETCH_ASSOC);
        $find = $pdo->prepare('SELECT id, submission_key FROM record_relation WHERE id = ? AND tenant_id = ? AND via IS NULL AND deleted = FALSE');
        $mark = $pdo->prepare("UPDATE record_relation SET via = ? WHERE id = ? AND tenant_id = ? AND via IS NULL");
        $marked = 0;
        foreach ($rows as $row) {
            $receipt = json_decode((string) $row['application_receipt'], true);
            if (!is_array($receipt) || !is_string($receipt['actorId'] ?? null) || ($receipt['purchaseId'] ?? null) !== $row['id'] ||
                ($receipt['tenantId'] ?? null) !== $row['tenant_id'] || !is_array($receipt['relationIds'] ?? null)) {
                continue;
            }
            $keys = [];
            for ($index = 0; $index < self::MAX_INDEX; $index++) {
                $keys[] = hash('sha256', $receipt['actorId'] . ':enrichment:' . $row['id'] . ':' . $index);
            }
            foreach ($receipt['relationIds'] as $relationId) {
                if (!is_string($relationId)) continue;
                $find->execute([$relationId, $row['tenant_id']]);
                $relation = $find->fetch(PDO::FETCH_ASSOC);
                if (!$relation || !in_array($relation['submission_key'], $keys, true)) continue;
                $mark->execute([Relations::VIA_ENRICHMENT, $relationId, $row['tenant_id']]);
                $marked += $mark->rowCount();
            }
        }
        return $marked;
    }
}

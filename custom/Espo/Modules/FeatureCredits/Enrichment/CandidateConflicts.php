<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Enrichment;

use Espo\Core\Exceptions\Conflict;
use PDO;

/** Durable quarantine on acquisition/group anchors; financial receipts remain immutable. */
final class CandidateConflicts
{
    public static function statusLocked(PDO $pdo,string $tenant,string $id): ?array
    {
        if (!$pdo->inTransaction()) throw new \LogicException('Candidate checks require the tenant transaction.');
        $q = $pdo->prepare('SELECT conflict_at,conflict_reason,deleted FROM enrichment_candidate_cache WHERE tenant_id=? AND id=?');
        $q->execute([$tenant,$id]); $row = $q->fetch(PDO::FETCH_ASSOC);
        if (!$row || $row['deleted']) throw new Conflict('Candidate acquisition is unavailable.');
        return $row['conflict_at'] === null ? null : ['reason' => $row['conflict_reason'],'detectedAt' => $row['conflict_at']];
    }

    /** Return the error instead of throwing: the caller must commit quarantine before reporting failure. */
    public static function blockLocked(PDO $pdo,string $tenant,array $ids,string $now,string $reason): Conflict
    {
        if (!$pdo->inTransaction()) throw new \LogicException('Candidate blocking requires the tenant transaction.');
        if (!in_array($reason,['identity_contradiction','identity_bridge'],true)) throw new \LogicException('Invalid candidate conflict.');
        foreach (array_unique($ids) as $id) {
            self::statusLocked($pdo,$tenant,$id);
            $pdo->prepare('UPDATE enrichment_candidate_cache SET conflict_at=?,conflict_reason=?
                WHERE tenant_id=? AND id=? AND conflict_at IS NULL')->execute([$now,$reason,$tenant,$id]);
        }
        return new Conflict('Candidate identity conflict blocks further purchase and application.');
    }
}

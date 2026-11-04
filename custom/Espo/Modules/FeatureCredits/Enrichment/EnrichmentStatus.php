<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Enrichment;

use Espo\Core\Exceptions\Conflict;
use PDO;

/**
 * Latest completed search per product, kept on the record so lists can filter without reading history.
 * Written in the same transaction that settles a quote; failed, waived or unavailable searches teach nothing.
 */
final class EnrichmentStatus
{
    /** product => [target type, table, date column, result column] */
    public const COLUMNS = [
        'email' => ['Contact', 'contact', 'email_enriched_at', 'email_enrichment_result'],
        'phone' => ['Contact', 'contact', 'phone_enriched_at', 'phone_enrichment_result'],
        'socios' => ['Account', 'account', 'socios_enriched_at', 'socios_enrichment_result'],
    ];

    /** @param list<string> $states enrichment_attempt states of one product */
    public static function fieldOutcome(array $states): ?string
    {
        if (array_intersect($states, ['matched', 'reused'])) return 'found';
        return in_array('no_match', $states, true) ? 'not_found' : null;
    }

    public static function discoveryOutcome(int $candidates, ?string $stopReason): ?string
    {
        if ($candidates > 0) return 'found';
        return in_array($stopReason, ['route_exhausted', 'page_limit', 'completion_limit'], true) ? 'not_found' : null;
    }

    /** Caller owns the transaction and has already settled the quote. */
    public static function recordLocked(PDO $pdo, array $quote, string $at): void
    {
        $request = Quotes::decode($quote)['request'];
        foreach (self::outcomes($pdo, $quote, $request) as $product => $result) {
            [$type, $table, $date, $column] = self::COLUMNS[$product];
            if ($request['targetType'] !== $type) continue;
            // Only a newer search replaces the stored one; replays and backfills are no-ops.
            $pdo->prepare("UPDATE $table SET $date=?, $column=? WHERE id=? AND tenant_id=? AND deleted=FALSE
                AND ($date IS NULL OR $date<? OR ($date=? AND ($column IS NULL OR $column<>?)))")
                ->execute([$at, $result, $request['targetId'], $request['tenantId'], $at, $at, $result]);
        }
    }

    /** One pass over every settled quote, oldest first. Safe to repeat on each rebuild. */
    public static function backfill(PDO $pdo): int
    {
        $rows = $pdo->query("SELECT q.*, COALESCE(u.settled_at, q.admitted_at) AS status_at FROM enrichment_quote q
            JOIN credit_usage u ON u.id=q.usage_id AND u.tenant_id=q.tenant_id
            WHERE q.deleted=FALSE AND u.deleted=FALSE AND u.state IN ('settled','released')
            ORDER BY status_at, q.id")->fetchAll(PDO::FETCH_ASSOC);
        $count = 0;
        foreach ($rows as $row) {
            try {
                self::recordLocked($pdo, $row, $row['status_at']);
                $count++;
            } catch (Conflict) {
                // A quote whose binding no longer verifies is not evidence of a search.
            }
        }
        return $count;
    }

    /** @return array<string, string> product => found|not_found */
    private static function outcomes(PDO $pdo, array $quote, array $request): array
    {
        if ($request['products'] === ['socios']) {
            $q = $pdo->prepare("SELECT COUNT(*) FROM enrichment_purchase WHERE quote_id=? AND tenant_id=?
                AND deleted=FALSE AND purchase_kind='candidate'");
            $q->execute([$quote['id'], $quote['tenant_id']]);
            $outcome = self::discoveryOutcome((int) $q->fetchColumn(), $quote['discovery_stop_reason'] ?? null);
            return $outcome === null ? [] : ['socios' => $outcome];
        }
        if ((new QuoteInput((object) $request))->isDiscovery()) return [];
        $q = $pdo->prepare('SELECT product, state FROM enrichment_attempt WHERE quote_id=? AND tenant_id=? AND deleted=FALSE');
        $q->execute([$quote['id'], $quote['tenant_id']]);
        $states = [];
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $attempt) $states[$attempt['product']][] = $attempt['state'];
        $result = [];
        foreach ($request['products'] as $product) {
            if (!isset(self::COLUMNS[$product])) continue;
            $outcome = self::fieldOutcome($states[$product] ?? []);
            if ($outcome !== null) $result[$product] = $outcome;
        }
        return $result;
    }
}

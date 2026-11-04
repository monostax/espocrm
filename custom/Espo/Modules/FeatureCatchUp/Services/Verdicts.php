<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCatchUp\Services;

use Espo\ORM\EntityManager;

/**
 * Stored analyses, one per deal revision, locale and evidence scope. Shared by every reader who can see the
 * same evidence, so a deal's owner and its admins pay for one analysis. `none` verdicts keep a quiet deal
 * out of the deck until its revision changes.
 */
class Verdicts
{
    public const VERDICTS = ['customer_waiting', 'follow_up_due', 'decided', 'next_step_missing', 'none'];

    /** A card nobody acted on stops counting for the badge after this long. */
    private const BADGE_HOURS = 24;

    public function __construct(private EntityManager $em) {}

    /** @return ?array{verdict: string, reason: ?string, card: ?array<string, mixed>} */
    public function find(string $opportunityId, string $revision, string $locale, string $evidenceScope): ?array
    {
        $query = $this->em->getPDO()->prepare(
            'SELECT verdict, reason, card FROM catch_up_verdict WHERE deleted = 0 AND opportunity_id = :id AND revision = :revision '
            . 'AND locale = :locale AND evidence_scope = :scope LIMIT 1'
        );
        $query->execute(['id' => $opportunityId, 'revision' => $revision, 'locale' => $locale, 'scope' => $evidenceScope]);
        $row = $query->fetch(\PDO::FETCH_ASSOC);
        if (!$row) return null;
        $card = $row['card'] ? json_decode((string) $row['card'], true) : null;
        return ['verdict' => $row['verdict'], 'reason' => $row['reason'], 'card' => is_array($card) ? $card : null];
    }

    /**
     * Deals among $revisions (id => revision) already judged `none` at that revision, by any reader.
     *
     * @param array<string, string> $revisions
     * @return array<string, true>
     */
    public function quiet(array $revisions): array
    {
        if (!$revisions) return [];
        $pairs = implode(' OR ', array_fill(0, count($revisions), '(opportunity_id = ? AND revision = ?)'));
        $values = [];
        foreach ($revisions as $id => $revision) array_push($values, $id, $revision);
        $query = $this->em->getPDO()->prepare("SELECT DISTINCT opportunity_id FROM catch_up_verdict WHERE deleted = 0 AND verdict = 'none' AND ($pairs)");
        $query->execute($values);
        return array_fill_keys($query->fetchAll(\PDO::FETCH_COLUMN), true);
    }

    /** @param ?array<string, mixed> $card */
    public function store(string $tenantId, string $opportunityId, string $revision, string $locale, string $evidenceScope,
        string $verdict, ?string $reason, ?array $card): void
    {
        // Only the newest analysis of a deal for this locale and scope is ever read again.
        $this->em->getPDO()->prepare(
            'DELETE FROM catch_up_verdict WHERE opportunity_id = :id AND locale = :locale AND evidence_scope = :scope AND revision <> :revision'
        )->execute(['id' => $opportunityId, 'locale' => $locale, 'scope' => $evidenceScope, 'revision' => $revision]);
        if ($this->find($opportunityId, $revision, $locale, $evidenceScope)) return;
        try {
            $this->em->createEntity('CatchUpVerdict', [
                'opportunityId' => $opportunityId, 'tenantId' => $tenantId, 'revision' => $revision, 'locale' => $locale,
                'evidenceScope' => $evidenceScope, 'verdict' => $verdict, 'reason' => $reason !== null ? mb_substr($reason, 0, 255) : null,
                'card' => $card !== null ? (object) $card : null,
            ]);
        } catch (\PDOException $e) {
            // A concurrent poll stored the same analysis first.
            if ((string) $e->getCode() !== '23000') throw $e;
        }
    }

    /** Someone acted on the deal's card: it leaves the badge. */
    public function resolve(string $opportunityId): void
    {
        $this->em->getPDO()->prepare('UPDATE catch_up_verdict SET resolved_at = UTC_TIMESTAMP() WHERE opportunity_id = :id AND resolved_at IS NULL')
            ->execute(['id' => $opportunityId]);
    }

    /**
     * Cards from the last day nobody acted on, on open deals in the reader's deck, that no message has overtaken
     * (a reply from the conversation itself settles a card without a review). No ACL check: it returns a number only.
     */
    public function count(string $tenantId, string $ownerSql, array $ownerParams, string $userId): int
    {
        $query = $this->em->getPDO()->prepare(
            'SELECT COUNT(DISTINCT v.opportunity_id) FROM catch_up_verdict v '
            . 'JOIN opportunity o ON o.id = v.opportunity_id AND o.deleted = 0 AND o.tenant_id = :tenant '
            . "AND o.status NOT IN ('Won','Lost') LEFT JOIN catch_up_deal_state s ON s.id = o.id AND s.deleted = 0 "
            . "WHERE v.deleted = 0 AND v.tenant_id = :verdictTenant AND v.verdict <> 'none' AND v.resolved_at IS NULL "
            . 'AND v.created_at >= UTC_TIMESTAMP() - INTERVAL ' . self::BADGE_HOURS . ' HOUR '
            . 'AND (s.last_message_at IS NULL OR s.last_message_at <= v.created_at) '
            . "AND o.id NOT IN (SELECT z.target_id FROM catch_up_snooze z WHERE z.deleted = 0 AND z.user_id = :user "
            . "AND z.target_type = 'Opportunity' AND z.snoozed_until > UTC_TIMESTAMP())" . $ownerSql
        );
        $query->execute(['tenant' => $tenantId, 'verdictTenant' => $tenantId, 'user' => $userId, ...$ownerParams]);
        return (int) $query->fetchColumn();
    }
}

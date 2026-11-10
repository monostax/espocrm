<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCatchUp\Services;

use Espo\ORM\EntityManager;

/**
 * Stored analyses, one per subject revision, locale and evidence scope. A subject is a deal (Negócios) or a
 * conversation without one (Atendimento). Shared by every reader who can see the same evidence, so an owner
 * and the admins pay for one analysis. `none` verdicts keep a quiet subject out of the deck until its
 * revision changes, and so do `scheduled` ones (loops open, none due yet).
 */
class Verdicts
{
    /** Card verdicts (Loops::BUCKETS) plus `scheduled` (open loops, none due: Agenda) and `none`. */
    public const VERDICTS = ['customer_waiting', 'follow_up_due', 'promise_due', 'decided', 'next_step_missing', 'new_deal', 'scheduled', 'none'];
    /** Subject type => the column holding its id. */
    public const SUBJECTS = ['Opportunity' => 'opportunity_id', 'ChatwootConversation' => 'conversation_id'];

    /** A card nobody acted on stops counting for the badge after this long. */
    private const BADGE_HOURS = 24;
    /** Cards checked for the badge; the rail shows 9+ anyway. */
    private const BADGE_LIMIT = 500;

    public function __construct(private EntityManager $em) {}

    /** @return ?array{verdict: string, reason: ?string, card: ?array<string, mixed>} */
    public function find(string $type, string $id, string $revision, string $locale, string $evidenceScope): ?array
    {
        $column = self::column($type);
        $query = $this->em->getPDO()->prepare(
            "SELECT verdict, reason, card FROM catch_up_verdict WHERE deleted = 0 AND $column = :id AND revision = :revision "
            . 'AND locale = :locale AND evidence_scope = :scope LIMIT 1'
        );
        $query->execute(['id' => $id, 'revision' => $revision, 'locale' => $locale, 'scope' => $evidenceScope]);
        $row = $query->fetch(\PDO::FETCH_ASSOC);
        if (!$row) return null;
        $card = $row['card'] ? json_decode((string) $row['card'], true) : null;
        return ['verdict' => $row['verdict'], 'reason' => $row['reason'], 'card' => is_array($card) ? $card : null];
    }

    /**
     * Subjects among $revisions (id => revision) already judged at that revision, by any reader, with the verdict.
     * A subject judged `none` or `scheduled` stays out of the deck; a card verdict tells the deck which tab
     * (Loops::BUCKETS) the subject belongs in before it is opened. A card wins over a quiet verdict another reader got.
     *
     * @param array<string, string> $revisions
     * @return array<string, string>
     */
    public function judged(string $type, array $revisions): array
    {
        if (!$revisions) return [];
        $column = self::column($type);
        $pairs = implode(' OR ', array_fill(0, count($revisions), "($column = ? AND revision = ?)"));
        $values = [];
        foreach ($revisions as $id => $revision) array_push($values, $id, $revision);
        $query = $this->em->getPDO()->prepare("SELECT DISTINCT $column, verdict FROM catch_up_verdict WHERE deleted = 0 AND ($pairs)");
        $query->execute($values);
        $judged = [];
        foreach ($query->fetchAll(\PDO::FETCH_NUM) as [$id, $verdict]) {
            if (!isset($judged[(string) $id]) || in_array($judged[(string) $id], Loops::QUIET, true)) $judged[(string) $id] = (string) $verdict;
        }
        return $judged;
    }

    /** @param ?array<string, mixed> $card */
    public function store(string $tenantId, string $type, string $id, string $revision, string $locale, string $evidenceScope,
        string $verdict, ?string $reason, ?array $card): void
    {
        $column = self::column($type);
        // Only the newest analysis of a subject for this locale and scope is ever read again.
        $this->em->getPDO()->prepare(
            "DELETE FROM catch_up_verdict WHERE $column = :id AND locale = :locale AND evidence_scope = :scope AND revision <> :revision"
        )->execute(['id' => $id, 'locale' => $locale, 'scope' => $evidenceScope, 'revision' => $revision]);
        if ($this->find($type, $id, $revision, $locale, $evidenceScope)) return;
        try {
            $this->em->createEntity('CatchUpVerdict', [
                $type === 'Opportunity' ? 'opportunityId' : 'conversationId' => $id,
                'tenantId' => $tenantId, 'revision' => $revision, 'locale' => $locale,
                'evidenceScope' => $evidenceScope, 'verdict' => $verdict, 'reason' => $reason !== null ? mb_substr($reason, 0, 255) : null,
                'card' => $card !== null ? (object) $card : null,
            ]);
        } catch (\PDOException $e) {
            // A concurrent poll stored the same analysis first.
            if ((string) $e->getCode() !== '23000') throw $e;
        }
    }

    /** Someone acted on the subject's card: it leaves the badge. */
    public function resolve(string $type, string $id): void
    {
        $column = self::column($type);
        $this->em->getPDO()->prepare("UPDATE catch_up_verdict SET resolved_at = UTC_TIMESTAMP() WHERE $column = :id AND resolved_at IS NULL")
            ->execute(['id' => $id]);
    }

    /**
     * Atendimento cards from the last day nobody acted on, for conversations of this account still without an
     * open deal, in the reader's deck and not overtaken by a message. Returns a number only.
     */
    /** @return list<string> conversation ids; the caller checks access and counts */
    public function cardConversations(string $tenantId, string $accountId, string $ownerSql, array $ownerParams, string $userId): array
    {
        $query = $this->em->getPDO()->prepare(
            'SELECT DISTINCT v.conversation_id FROM catch_up_verdict v '
            . 'JOIN chatwoot_conversation c ON c.id = v.conversation_id AND c.deleted = 0 AND c.chatwoot_account_id = :account '
            . 'JOIN chatwoot_account a ON a.id = c.chatwoot_account_id '
            . "WHERE v.deleted = 0 AND v.tenant_id = :tenant AND v.verdict NOT IN ('none', 'scheduled') AND v.resolved_at IS NULL "
            . 'AND v.created_at >= UTC_TIMESTAMP() - INTERVAL ' . self::BADGE_HOURS . ' HOUR '
            . 'AND (c.last_activity_at IS NULL OR c.last_activity_at <= v.created_at) '
            . 'AND NOT EXISTS (' . ServiceFeed::OPEN_DEAL_SQL . ') '
            . "AND c.id NOT IN (SELECT z.target_id FROM catch_up_snooze z WHERE z.deleted = 0 AND z.user_id = :user "
            . "AND z.target_type = 'ChatwootConversation' AND z.snoozed_until > UTC_TIMESTAMP())" . $ownerSql
            . ' LIMIT ' . self::BADGE_LIMIT
        );
        $query->execute(['tenant' => $tenantId, 'account' => $accountId, 'user' => $userId, ...$ownerParams]);
        return array_map('strval', $query->fetchAll(\PDO::FETCH_COLUMN));
    }

    private static function column(string $type): string
    {
        return self::SUBJECTS[$type] ?? throw new \InvalidArgumentException("Unsupported Em dia subject: $type");
    }

    /**
     * Cards from the last day nobody acted on, on open deals in the reader's deck, that no message has overtaken
     * (a reply from the conversation itself settles a card without a review). The caller keeps only deals with a
     * conversation the reader may open in Chatwoot and counts them.
     *
     * @return list<string> deal ids
     */
    public function cardDeals(string $tenantId, string $ownerSql, array $ownerParams, string $userId): array
    {
        $query = $this->em->getPDO()->prepare(
            'SELECT DISTINCT v.opportunity_id FROM catch_up_verdict v '
            . 'JOIN opportunity o ON o.id = v.opportunity_id AND o.deleted = 0 AND o.tenant_id = :tenant '
            . "AND o.status NOT IN ('Won','Lost') LEFT JOIN catch_up_deal_state s ON s.id = o.id AND s.deleted = 0 "
            . "WHERE v.deleted = 0 AND v.tenant_id = :verdictTenant AND v.verdict NOT IN ('none', 'scheduled') AND v.resolved_at IS NULL "
            . 'AND v.created_at >= UTC_TIMESTAMP() - INTERVAL ' . self::BADGE_HOURS . ' HOUR '
            . 'AND (s.last_message_at IS NULL OR s.last_message_at <= v.created_at) '
            . "AND o.id NOT IN (SELECT z.target_id FROM catch_up_snooze z WHERE z.deleted = 0 AND z.user_id = :user "
            . "AND z.target_type = 'Opportunity' AND z.snoozed_until > UTC_TIMESTAMP())" . $ownerSql
            . ' LIMIT ' . self::BADGE_LIMIT
        );
        $query->execute(['tenant' => $tenantId, 'verdictTenant' => $tenantId, 'user' => $userId, ...$ownerParams]);
        return array_map('strval', $query->fetchAll(\PDO::FETCH_COLUMN));
    }
}

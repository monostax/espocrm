<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCatchUp\Services;

use DateTimeImmutable;
use Espo\ORM\EntityManager;

/**
 * Stored analyses, one per subject revision, locale and evidence scope. A subject is a deal (Negócios) or a
 * conversation without one (Atendimento). Shared by every reader who can see the same evidence, so an owner
 * and the admins pay for one analysis. `none` verdicts keep a quiet subject out of the deck until its
 * revision changes, and so do `scheduled` ones (loops open, none due yet). A verdict a loop decides keeps that
 * loop's basis and the time the clock next changes it (`flips_at`); flipDue() picks it again then, in code.
 */
class Verdicts
{
    /** Card verdicts (Loops::BUCKETS) plus `scheduled` (open loops, none due: Agenda) and `none`. */
    public const VERDICTS = ['customer_waiting', 'follow_up_due', 'promise_due', 'decided', 'next_step_missing', 'new_deal', 'scheduled', 'none'];
    /** Subject type => the column holding its id. */
    public const SUBJECTS = ['Opportunity' => 'opportunity_id', 'ChatwootConversation' => 'conversation_id'];

    /** Verdicts read for the tab counts: newest first, any verdict (a newer quiet one hides an older card). */
    private const COUNT_LIMIT = 5000;
    /**
     * A subject whose last message is Feed::SETTLE_MINUTES to this old, with no analysis since, has settled and its analysis
     * is on the way. One still in conversation is not counted (on a busy account there always is one, decided 2026-11-11),
     * and an analysis that never lands stops counting after this.
     */
    public const PENDING_MINUTES = Feed::SETTLE_MINUTES + 15;
    /** Verdicts picked again per call when their time comes; the rest on the next read. */
    private const FLIP_BATCH = 200;

    /** @var array<string, true> tenants whose due verdicts this request already picked again */
    private array $flipped = [];

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
     * A card someone acted on at this revision (resolve(): "Já resolvi", a reply sent from the card) counts as `none`
     * for everyone, as it does for the badge: the subject comes back once its revision moves.
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
        $query = $this->em->getPDO()->prepare(
            "SELECT DISTINCT $column, verdict, resolved_at IS NOT NULL FROM catch_up_verdict WHERE deleted = 0 AND ($pairs)"
        );
        $query->execute($values);
        $judged = [];
        $resolved = [];
        foreach ($query->fetchAll(\PDO::FETCH_NUM) as [$id, $verdict, $done]) {
            if ((int) $done && !in_array($verdict, Loops::QUIET, true)) $resolved[(string) $id] = true;
            if (!isset($judged[(string) $id]) || in_array($judged[(string) $id], Loops::QUIET, true)) $judged[(string) $id] = (string) $verdict;
        }
        foreach (array_keys($resolved) as $id) $judged[$id] = 'none';
        return $judged;
    }

    /**
     * The card verdicts of a subject at this revision, acted on or not, with what each was stored under.
     *
     * @return list<array{tenantId: string, locale: string, evidenceScope: string, card: ?array<string, mixed>}>
     */
    public function cards(string $type, string $id, string $revision): array
    {
        $column = self::column($type);
        $query = $this->em->getPDO()->prepare("SELECT tenant_id, locale, evidence_scope, card FROM catch_up_verdict WHERE deleted = 0 "
            . "AND $column = :id AND revision = :revision AND verdict NOT IN ('none', 'scheduled')");
        $query->execute(['id' => $id, 'revision' => $revision]);
        return array_map(function (array $row) {
            $card = $row['card'] ? json_decode((string) $row['card'], true) : null;
            return ['tenantId' => (string) $row['tenant_id'], 'locale' => (string) $row['locale'], 'evidenceScope' => (string) $row['evidence_scope'],
                'card' => is_array($card) ? $card : null];
        }, $query->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * @param ?array<string, mixed> $card
     * @param ?array<string, mixed> $basis what the verdict is picked again from as time passes (Loops::basis)
     * @param ?int $flipsAt when the clock next changes it (Loops::flipsAt)
     */
    public function store(string $tenantId, string $type, string $id, string $revision, string $locale, string $evidenceScope,
        string $verdict, ?string $reason, ?array $card, ?array $basis = null, ?int $flipsAt = null): void
    {
        $column = self::column($type);
        // Only the newest analysis of a subject for this locale and scope is ever read again.
        $this->em->getPDO()->prepare(
            "DELETE FROM catch_up_verdict WHERE $column = :id AND locale = :locale AND evidence_scope = :scope AND revision <> :revision"
        )->execute(['id' => $id, 'locale' => $locale, 'scope' => $evidenceScope, 'revision' => $revision]);
        $stored = $this->find($type, $id, $revision, $locale, $evidenceScope);
        if ($stored && !self::outdated($stored)) return;
        // A card the clock moved to another loop, or an outdated one, was analysed again: the new analysis replaces it.
        if ($stored) $this->em->getPDO()->prepare(
            "DELETE FROM catch_up_verdict WHERE $column = :id AND locale = :locale AND evidence_scope = :scope AND revision = :revision"
        )->execute(['id' => $id, 'locale' => $locale, 'scope' => $evidenceScope, 'revision' => $revision]);
        try {
            $this->em->createEntity('CatchUpVerdict', [
                $type === 'Opportunity' ? 'opportunityId' : 'conversationId' => $id,
                'tenantId' => $tenantId, 'revision' => $revision, 'locale' => $locale,
                'evidenceScope' => $evidenceScope, 'verdict' => $verdict, 'reason' => $reason !== null ? mb_substr($reason, 0, 255) : null,
                'card' => $card !== null ? (object) $card : null,
                'basis' => $basis !== null ? (object) $basis : null, 'flipsAt' => self::at($flipsAt),
            ]);
        } catch (\PDOException $e) {
            // A concurrent poll stored the same analysis first.
            if ((string) $e->getCode() !== '23000') throw $e;
        }
    }

    /**
     * The unresolved `new_deal` verdicts of these conversations at their current revision: the deals the analysis
     * found that nobody created or dismissed yet. A message since then moves the revision, so a proposal that the
     * conversation has overtaken is not returned.
     *
     * @param array<string, string> $revisions conversation id => current revision
     * @return array<string, array{card: array<string, mixed>, reason: ?string}>
     */
    public function proposals(array $revisions): array
    {
        if (!$revisions) return [];
        $pairs = implode(' OR ', array_fill(0, count($revisions), '(conversation_id = ? AND revision = ?)'));
        $values = [];
        foreach ($revisions as $id => $revision) array_push($values, $id, $revision);
        $query = $this->em->getPDO()->prepare("SELECT conversation_id, card, reason FROM catch_up_verdict WHERE deleted = 0 "
            . "AND verdict = 'new_deal' AND resolved_at IS NULL AND ($pairs) ORDER BY created_at DESC");
        $query->execute($values);
        $found = [];
        foreach ($query->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $card = json_decode((string) $row['card'], true);
            if (!isset($found[(string) $row['conversation_id']]) && is_array($card)) {
                $found[(string) $row['conversation_id']] = ['card' => $card, 'reason' => $row['reason']];
            }
        }
        return $found;
    }

    /**
     * A stored card that is analysed again when the deck reaches it, at the same revision: one the clock moved to a loop
     * its copy wasn't written for (`redraft`), or a found deal from before prompt version 13, which proposed no funnel or
     * stage. Only those cards are analysed again; the revision stays, so no other deal or conversation is.
     *
     * @param array{verdict?: ?string, card?: ?array<string, mixed>} $stored
     */
    public static function outdated(array $stored): bool
    {
        if (!empty($stored['card']['redraft'])) return true;
        $deal = $stored['card']['proposedDeal'] ?? null;
        return ($stored['verdict'] ?? null) === 'new_deal' && is_array($deal) && !array_key_exists('funnelId', $deal);
    }

    /** Someone acted on the subject's card: it leaves the deck and the counts until the subject moves, the clock included. */
    public function resolve(string $type, string $id): void
    {
        $column = self::column($type);
        $this->em->getPDO()->prepare("UPDATE catch_up_verdict SET resolved_at = UTC_TIMESTAMP(), flips_at = NULL WHERE $column = :id AND resolved_at IS NULL")
            ->execute(['id' => $id]);
    }

    /**
     * "Já resolvi" kept the card's loop and nothing else is due now: the subject's verdicts at this revision become what
     * its other loops make (`scheduled` or `none`), so the clock brings the next loop back as a card (flipDue()).
     *
     * @param ?array{id: ?string, side: string, text: string, dueAt: ?string} $loop
     * @param ?array<string, mixed> $basis
     */
    public function settleAs(string $type, string $id, string $revision, string $verdict, ?array $loop, ?array $basis, ?int $flipsAt, string $reason): void
    {
        $column = self::column($type);
        $pdo = $this->em->getPDO();
        $rows = $pdo->prepare("SELECT id, card FROM catch_up_verdict WHERE deleted = 0 AND $column = :id AND revision = :revision");
        $rows->execute(['id' => $id, 'revision' => $revision]);
        $update = $pdo->prepare('UPDATE catch_up_verdict SET verdict = :verdict, reason = :reason, card = :card, basis = :basis, '
            . 'flips_at = :flips, resolved_at = NULL WHERE id = :row');
        foreach ($rows->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $card = $row['card'] ? json_decode((string) $row['card'], true) : null;
            $update->execute([
                'verdict' => $verdict, 'reason' => $reason, 'row' => $row['id'], 'flips' => self::at($flipsAt),
                'card' => is_array($card) ? json_encode(self::moved($card, $loop), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) : null,
                'basis' => $basis !== null ? json_encode($basis, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) : null,
            ]);
        }
    }

    /**
     * The tenant's verdicts whose time came, picked again from their stored loops and the clock (Loops), in code: a
     * promise came due, a quiet customer crossed the follow-up threshold. No analysis runs; a card that now shows a loop
     * its copy wasn't written for is marked `redraft`, and the deck analyses it again when it reaches it. Once per request.
     */
    public function flipDue(string $tenantId): void
    {
        if (isset($this->flipped[$tenantId])) return;
        $this->flipped[$tenantId] = true;
        $pdo = $this->em->getPDO();
        $query = $pdo->prepare('SELECT id, card, basis FROM catch_up_verdict WHERE deleted = 0 AND tenant_id = :tenant '
            . 'AND flips_at IS NOT NULL AND flips_at <= UTC_TIMESTAMP() AND resolved_at IS NULL LIMIT ' . self::FLIP_BATCH);
        $query->execute(['tenant' => $tenantId]);
        $rows = $query->fetchAll(\PDO::FETCH_ASSOC);
        if (!$rows) return;
        $now = new DateTimeImmutable('@' . time());
        $update = $pdo->prepare('UPDATE catch_up_verdict SET verdict = :verdict, card = :card, flips_at = :flips WHERE id = :row');
        foreach ($rows as $row) {
            $basis = $row['basis'] ? json_decode((string) $row['basis'], true) : null;
            $card = $row['card'] ? json_decode((string) $row['card'], true) : null;
            if (!is_array($basis) || !is_array($card)) {
                $pdo->prepare('UPDATE catch_up_verdict SET flips_at = NULL WHERE id = :row')->execute(['row' => $row['id']]);
                continue;
            }
            $open = Loops::open(['promises' => $basis['loops'] ?? []]);
            ['verdict' => $verdict, 'loop' => $loop] = $open ? Loops::pick($open, Loops::factsAt($basis, $now), $now) : ['verdict' => 'none', 'loop' => null];
            $update->execute([
                'verdict' => $verdict, 'row' => $row['id'], 'flips' => self::at(Loops::flipsAt($basis, $now)),
                'card' => json_encode(self::moved($card, $loop), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            ]);
        }
    }

    /**
     * A stored card whose loop the clock (or "Já resolvi") changed: it shows the new loop. Copy written for another loop
     * (`draftFor`, set when the card was analysed) is written again: the card is marked `redraft`.
     *
     * @param array<string, mixed> $card
     * @param ?array<string, mixed> $loop
     * @return array<string, mixed>
     */
    public static function moved(array $card, ?array $loop): array
    {
        $draftFor = $card['draftFor'] ?? $card['promise'] ?? null;
        $card['promise'] = $loop;
        if ($loop && !Loops::same($loop, is_array($draftFor) ? $draftFor : null)) {
            $card['redraft'] = true;
            $card['headline'] = $loop['text'];
        }
        return $card;
    }

    /** A Unix time as the datetime columns store it (UTC). */
    private static function at(?int $time): ?string
    {
        return $time === null ? null : gmdate('Y-m-d H:i:s', $time);
    }

    /**
     * The verdict each Atendimento conversation of the reader's deck has now, for the tab counts: conversations of this
     * account still without an open deal, active in the deck's window, not labeled personal, automated or team, not snoozed
     * by the reader, unresolved and not overtaken by a message since (activity doesn't count). A conversation judged for
     * several readers or locales counts by its newest verdict. The caller checks access, keeps one copy per WhatsApp group
     * and counts the card verdicts by tab.
     *
     * @return array<string, array{verdict: string, contact: ?string, grp: int}> conversation id => newest verdict
     */
    public function cardConversations(string $tenantId, string $accountId, string $ownerSql, array $ownerParams, string $userId): array
    {
        $this->flipDue($tenantId);
        $query = $this->em->getPDO()->prepare(
            'SELECT v.conversation_id, v.verdict, c.chatwoot_contact_id, (COALESCE(ct.identifier, \'\') LIKE \'%@g.us\') FROM catch_up_verdict v '
            . 'JOIN chatwoot_conversation c ON c.id = v.conversation_id AND c.deleted = 0 AND c.chatwoot_account_id = :account '
            . 'JOIN chatwoot_account a ON a.id = c.chatwoot_account_id '
            . self::CONVERSATION_FILTERS_SQL
            . 'WHERE v.deleted = 0 AND v.tenant_id = :tenant AND v.resolved_at IS NULL '
            . 'AND c.last_activity_at >= UTC_TIMESTAMP() - INTERVAL ' . ServiceFeed::WINDOW_DAYS . ' DAY '
            . 'AND ' . self::LAST_MESSAGE_SQL . ' <= v.created_at '
            . self::CONVERSATION_DECK_SQL . $ownerSql
            . ' ORDER BY v.created_at DESC LIMIT ' . self::COUNT_LIMIT
        );
        $query->execute(['tenant' => $tenantId, 'account' => $accountId, 'user' => $userId, ...$ownerParams]);
        $found = [];
        foreach ($query->fetchAll(\PDO::FETCH_NUM) as [$id, $verdict, $contact, $grp]) {
            $found[(string) $id] ??= ['verdict' => (string) $verdict, 'contact' => $contact !== null ? (string) $contact : null, 'grp' => (int) $grp];
        }
        return $found;
    }

    /**
     * Atendimento conversations of the reader's deck that settled in the last PENDING_MINUTES (last message SETTLE_MINUTES
     * to PENDING_MINUTES old) with no analysis since: their analysis is on the way. Same deck filters as cardConversations().
     *
     * @return array<string, array{contact: ?string, grp: int}>
     */
    public function pendingConversations(string $accountId, string $ownerSql, array $ownerParams, string $userId): array
    {
        $query = $this->em->getPDO()->prepare(
            'SELECT c.id, c.chatwoot_contact_id, (COALESCE(ct.identifier, \'\') LIKE \'%@g.us\') FROM chatwoot_conversation c '
            . 'JOIN chatwoot_account a ON a.id = c.chatwoot_account_id '
            . self::CONVERSATION_FILTERS_SQL
            . 'WHERE c.deleted = 0 AND c.chatwoot_account_id = :account '
            . 'AND c.last_activity_at >= UTC_TIMESTAMP() - INTERVAL ' . self::PENDING_MINUTES . ' MINUTE '
            . 'AND ' . self::LAST_MESSAGE_SQL . ' >= UTC_TIMESTAMP() - INTERVAL ' . self::PENDING_MINUTES . ' MINUTE '
            . 'AND ' . self::LAST_MESSAGE_SQL . ' <= UTC_TIMESTAMP() - INTERVAL ' . Feed::SETTLE_MINUTES . ' MINUTE '
            . 'AND NOT EXISTS (SELECT 1 FROM catch_up_verdict v WHERE v.deleted = 0 AND v.conversation_id = c.id AND v.created_at >= '
            . self::LAST_MESSAGE_SQL . ') '
            . self::CONVERSATION_DECK_SQL . $ownerSql
            . ' LIMIT ' . self::COUNT_LIMIT
        );
        $query->execute(['account' => $accountId, 'user' => $userId, ...$ownerParams]);
        $found = [];
        foreach ($query->fetchAll(\PDO::FETCH_NUM) as [$id, $contact, $grp]) {
            $found[(string) $id] = ['contact' => $contact !== null ? (string) $contact : null, 'grp' => (int) $grp];
        }
        return $found;
    }

    /**
     * The verdict each open deal of the reader's deck has now, for the tab counts: in the deck's window (not dormant), not
     * snoozed by the reader, unresolved and not overtaken by a message since. A deal judged for several readers or locales
     * counts by its newest verdict. The caller keeps the deals with a conversation the reader may open and counts the card
     * verdicts by tab.
     *
     * @return array<string, string> deal id => newest verdict
     */
    public function cardDeals(string $tenantId, string $ownerSql, array $ownerParams, string $userId): array
    {
        $this->flipDue($tenantId);
        $query = $this->em->getPDO()->prepare(
            'SELECT v.opportunity_id, v.verdict FROM catch_up_verdict v '
            . 'JOIN opportunity o ON o.id = v.opportunity_id AND o.deleted = 0 AND o.tenant_id = :tenant '
            . "AND o.status NOT IN ('Won','Lost') LEFT JOIN catch_up_deal_state s ON s.id = o.id AND s.deleted = 0 "
            . 'WHERE v.deleted = 0 AND v.tenant_id = :verdictTenant AND v.resolved_at IS NULL '
            . 'AND (s.last_message_at IS NULL OR s.last_message_at <= v.created_at) '
            . 'AND (s.last_message_at IS NULL OR s.last_message_at >= UTC_TIMESTAMP() - INTERVAL ' . Feed::DORMANT_DAYS . ' DAY) '
            . self::DEAL_DECK_SQL . $ownerSql
            . ' ORDER BY v.created_at DESC LIMIT ' . self::COUNT_LIMIT
        );
        $query->execute(['tenant' => $tenantId, 'verdictTenant' => $tenantId, 'user' => $userId, ...$ownerParams]);
        $found = [];
        foreach ($query->fetchAll(\PDO::FETCH_NUM) as [$id, $verdict]) $found[(string) $id] ??= (string) $verdict;
        return $found;
    }

    /**
     * Open deals of the reader's deck that settled in the last PENDING_MINUTES (last message SETTLE_MINUTES to
     * PENDING_MINUTES old) with no analysis since: their analysis is on the way.
     *
     * @return list<string>
     */
    public function pendingDeals(string $tenantId, string $ownerSql, array $ownerParams, string $userId): array
    {
        $query = $this->em->getPDO()->prepare(
            'SELECT o.id FROM opportunity o JOIN catch_up_deal_state s ON s.id = o.id AND s.deleted = 0 '
            . "WHERE o.deleted = 0 AND o.tenant_id = :tenant AND o.status NOT IN ('Won','Lost') "
            . 'AND s.last_message_at >= UTC_TIMESTAMP() - INTERVAL ' . self::PENDING_MINUTES . ' MINUTE '
            . 'AND s.last_message_at <= UTC_TIMESTAMP() - INTERVAL ' . Feed::SETTLE_MINUTES . ' MINUTE '
            . 'AND NOT EXISTS (SELECT 1 FROM catch_up_verdict v WHERE v.deleted = 0 AND v.opportunity_id = o.id AND v.created_at >= s.last_message_at) '
            . self::DEAL_DECK_SQL . $ownerSql
            . ' LIMIT ' . self::COUNT_LIMIT
        );
        $query->execute(['tenant' => $tenantId, 'user' => $userId, ...$ownerParams]);
        return array_map('strval', $query->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** The newest message either way of conversation `c` (activity left out; a private note counts as sent). */
    private const LAST_MESSAGE_SQL = "GREATEST(COALESCE(c.last_message_received_at, '1970-01-01'), COALESCE(c.last_message_sent_at, '1970-01-01'))";
    /** Joins for conversation `c`'s contact `ct` and its label `p`. */
    private const CONVERSATION_FILTERS_SQL = 'LEFT JOIN chatwoot_contact ct ON ct.id = c.chatwoot_contact_id AND ct.deleted = 0 '
        . 'LEFT JOIN catch_up_party p ON p.chatwoot_account_id = c.chatwoot_account_id AND p.chatwoot_contact_id = ct.chatwoot_contact_id AND p.deleted = 0 ';
    /** Conversation `c` belongs in the reader's Atendimento deck (ServiceFeed::candidates()), minus the settle wait and the owner rule. */
    private const CONVERSATION_DECK_SQL = "AND COALESCE(ct.identifier, '') <> 'whatsapp.integration' "
        . "AND (p.party_class IS NULL OR p.party_class IN ('customer', 'partner')) "
        . 'AND NOT EXISTS (' . ServiceFeed::OPEN_DEAL_SQL . ') '
        . 'AND c.id NOT IN (SELECT z.target_id FROM catch_up_snooze z WHERE z.deleted = 0 AND z.user_id = :user '
        . "AND z.target_type = 'ChatwootConversation' AND z.snoozed_until > UTC_TIMESTAMP())";
    /** Deal `o` belongs in the reader's Negócios deck (Feed::candidateIds()), minus the settle wait, the window and the owner rule. */
    private const DEAL_DECK_SQL = 'AND EXISTS (SELECT 1 FROM chatwoot_conversation_opportunity r WHERE r.opportunity_id = o.id AND r.deleted = 0) '
        . 'AND o.id NOT IN (SELECT z.target_id FROM catch_up_snooze z WHERE z.deleted = 0 AND z.user_id = :user '
        . "AND z.target_type = 'Opportunity' AND z.snoozed_until > UTC_TIMESTAMP())";

    private static function column(string $type): string
    {
        return self::SUBJECTS[$type] ?? throw new \InvalidArgumentException("Unsupported Em dia subject: $type");
    }
}

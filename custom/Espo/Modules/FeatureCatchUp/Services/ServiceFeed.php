<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCatchUp\Services;

use DateTimeImmutable;
use Espo\Core\Acl;
use Espo\Core\Api\Request;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Conflict;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Select\SelectBuilderFactory;
use Espo\Core\Utils\DataCache;
use Espo\Entities\User;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * The Atendimento lane (gtm/05 §11, §12 step 2): conversations with no open deal, one card per conversation,
 * judged like deals by the analysis. Everything stays inside the deck's Chatwoot account and the reader's
 * Chatwoot access: candidates pass the ChatwootConversation access filter (inbox membership), and every
 * snapshot or action checks the conversation again. Contacts labeled personal, automated or team (Parties)
 * never become candidates.
 */
class ServiceFeed
{
    private const PAGE_SIZE = 20;
    /** Conversations with activity in this window are candidates. */
    private const WINDOW_DAYS = 30;
    /** An open deal linked to conversation `c`: such a conversation is a Negócios card instead. */
    public const OPEN_DEAL_SQL = 'SELECT 1 FROM chatwoot_conversation_opportunity r JOIN opportunity o ON o.id = r.opportunity_id '
        . "AND o.deleted = 0 AND o.status NOT IN ('Won','Lost') WHERE r.chatwoot_conversation_id = c.id AND r.deleted = 0";
    private const WAITING = 'c.last_message_received_at IS NOT NULL AND (c.last_message_sent_at IS NULL OR c.last_message_received_at > c.last_message_sent_at)';
    /** Waiting on us for up to Feed::WAITING_FIRST_DAYS: these come first, longest wait first. */
    private const RECENT_WAIT = self::WAITING . ' AND c.last_message_received_at >= UTC_TIMESTAMP() - INTERVAL ' . Feed::WAITING_FIRST_DAYS . ' DAY';

    public function __construct(
        private EntityManager $em,
        private SelectBuilderFactory $select,
        private Acl $acl,
        private User $user,
        private Workspace $workspace,
        private Ownership $owners,
        private Verdicts $verdicts,
        private Transcript $transcript,
        private Parties $parties,
        private DataCache $cache,
        private Records $records,
        private ChatwootAccess $access,
    ) {}

    public function list(Request $request): array
    {
        $this->authorize();
        $account = $this->workspace->resolve($request)['account'];
        $now = Workspace::now($request);
        $cursor = (string) ($request->getQueryParam('cursor') ?? '');
        if ($cursor !== '' && !ctype_digit($cursor)) throw new BadRequest('Invalid cursor.');
        $offset = (int) $cursor;
        $limit = Workspace::limit($request, self::PAGE_SIZE);
        $rows = $this->candidates($account->getId(), $offset, $limit);
        // The CRM's inbox filter, then Chatwoot's own rules (teams, participation, custom roles, admins' own inboxes).
        $conversations = $this->access->filter($this->readable(array_keys($rows)));
        $dues = $this->records->dues('ChatwootConversation', array_keys($conversations));
        $judged = $this->verdicts->judged('ChatwootConversation',
            array_map(fn ($c) => Revision::ofConversation($c, $now, $dues[$c->getId()] ?? null), $conversations));
        $names = $this->userNames(array_filter(array_column($rows, 'owner')));
        $items = [];
        $groups = [];
        foreach ($conversations as $id => $conversation) {
            // A WhatsApp group is mirrored once per member number; the reader gets the first copy they may open.
            if ($rows[$id]['grp']) {
                if (isset($groups[$rows[$id]['contact']])) continue;
                $groups[$rows[$id]['contact']] = true;
            }
            if (in_array($judged[$id] ?? null, Loops::QUIET, true)) continue;
            $items[] = [...$this->candidate($conversation, $names[$rows[$id]['owner'] ?? ''] ?? null, (bool) $rows[$id]['grp'], $now),
                'verdict' => $judged[$id] ?? null];
        }
        return ['items' => $items, 'cursor' => count($rows) === $limit ? (string) ($offset + $limit) : null];
    }

    /** Atendimento cards in the reader's deck nobody acted on, for the badge. */
    public function count(string $tenantId, Entity $account): int
    {
        [$sql, $params] = $this->owners->conversationClause();
        return count($this->access->openConversations(
            $this->verdicts->cardConversations($tenantId, $account->getId(), $sql, $params, $this->user->getId())));
    }

    public function snapshot(string $id, Request $request): array
    {
        $this->authorize();
        ['tenant' => $tenant, 'policy' => $policy, 'account' => $account] = $this->workspace->resolve($request);
        $now = Workspace::now($request);
        $conversation = $this->conversation($id, $account);
        ['evidence' => $messages, 'participants' => $participants] = $this->transcript->read([$id => $conversation], $now, true);
        $this->parties->remember($participants);
        usort($messages, fn ($a, $b) => [$a['ts'], $a['id']] <=> [$b['ts'], $b['id']]);
        $evidence = array_map(fn ($entry) => array_diff_key($entry, ['ts' => true]), $messages);
        $own = array_values(array_filter($participants, fn ($p) => !empty($p['own'])))[0] ?? null;
        $owner = $this->owner($id);
        $candidate = $this->candidate($conversation, $owner ? ($this->userNames([$owner])[$owner] ?? null) : null, (bool) ($own['group'] ?? false), $now);
        $revision = Revision::ofConversation($conversation, $now, $this->records->dues('ChatwootConversation', [$id])[$id] ?? null);
        $revisions = ['due' => Revision::ofConversation($conversation, $now, $now->getTimestamp()), 'notDue' => Revision::ofConversation($conversation, $now)];
        $scope = Feed::evidenceScope($evidence);
        $sourceScope = Records::scope([$id]);
        $result = [
            ...$candidate,
            'tenantId' => $tenant->getId(), 'catchUpPolicy' => $policy,
            'revision' => $revision, 'revisions' => $revisions, 'evidenceScope' => $scope, 'evidence' => $evidence, 'participants' => $participants,
            'sourceScope' => $sourceScope, 'record' => $this->records->load('ChatwootConversation', $id, $sourceScope),
            'facts' => $this->facts($candidate, $now), 'nextAction' => null, 'closable' => ['won' => false, 'lost' => false],
            'conversation' => [
                'conversationId' => (int) $conversation->get('chatwootConversationId'),
                'accountId' => (int) $account->get('chatwootAccountId'), 'inboxName' => $conversation->get('inboxName'),
            ],
            'party' => $own ? ['id' => $own['id'], 'label' => $own['label'] ?? null] : null,
        ];
        $result['snapshot'] = hash('sha256', json_encode([$id, $this->user->getId(), $revision, $scope], JSON_THROW_ON_ERROR));
        $this->cache->store($this->snapshotKey($id), [
            'token' => $result['snapshot'], 'revision' => $revision, 'expires' => time() + 86400, 'participants' => $participants,
            'alternates' => array_values($revisions),
        ]);
        return $result;
    }

    /** Acting on a card first confirms the conversation hasn't moved since the card was shown. */
    public function review(string $id, string $token, Request $request): void
    {
        $this->authorize();
        $conversation = $this->conversation($id, $this->workspace->resolve($request)['account']);
        $snapshot = $this->shown($id, $token);
        $now = Workspace::now($request);
        $current = Revision::ofConversation($conversation, $now, $this->records->dues('ChatwootConversation', [$id])[$id] ?? null);
        if (!in_array($current, [$snapshot['revision'], ...($snapshot['alternates'] ?? [])], true)) {
            throw new Conflict('This conversation changed since the card was shown.');
        }
        $this->verdicts->resolve('ChatwootConversation', $id);
    }

    /** "Já resolvi": the card leaves the deck and the badge until the conversation moves again. */
    public function resolved(string $id, Request $request): void
    {
        $this->authorize();
        $this->conversation($id, $this->workspace->resolve($request)['account']);
        $this->verdicts->resolve('ChatwootConversation', $id);
    }

    /**
     * "Não é cliente" / "é da equipe" from a card: a person labels a contact or group shown on it. Only a
     * participant of the shown card can be labeled, and only by someone who may open the conversation.
     */
    public function label(string $id, string $contactId, string $class, string $token, Request $request): array
    {
        $this->authorize();
        $this->conversation($id, $this->workspace->resolve($request)['account']);
        if (!isset(Parties::LABELS[$class])) throw new BadRequest('Unknown class.');
        $participant = null;
        foreach ($this->shown($id, $token)['participants'] ?? [] as $candidate) {
            if ((string) $candidate['id'] === $contactId) $participant = $candidate;
        }
        if (!$participant) throw new BadRequest('This contact is not on the card.');
        $this->parties->set($participant, $class);
        if (!empty($participant['own']) && !in_array($class, ['customer', 'partner'], true)) $this->verdicts->resolve('ChatwootConversation', $id);
        return ['id' => $contactId, 'class' => $class, 'label' => Parties::LABELS[$class]];
    }

    /**
     * Conversations of the deck's account without an open deal, settled and active in the window, not
     * snoozed, not labeled personal, automated or team, in the reader's deck. Customers waiting on us come
     * first (freshest first), then chats quiet for over two days, then the rest by latest activity.
     *
     * @return array<string, array{owner: ?string, grp: int, contact: ?string}> keyed by conversation id, in order
     */
    private function candidates(string $accountId, int $offset, int $limit): array
    {
        [$owner, $params] = $this->owners->conversationClause();
        $query = $this->em->getPDO()->prepare(
            'SELECT c.id, c.chatwoot_contact_id AS contact, ' . Ownership::CONVERSATION_OWNER_SQL . ' AS owner, '
            . '(COALESCE(ct.identifier, \'\') LIKE \'%@g.us\') AS grp '
            . 'FROM chatwoot_conversation c JOIN chatwoot_account a ON a.id = c.chatwoot_account_id '
            . 'LEFT JOIN chatwoot_contact ct ON ct.id = c.chatwoot_contact_id AND ct.deleted = 0 '
            . 'LEFT JOIN catch_up_party p ON p.chatwoot_account_id = c.chatwoot_account_id AND p.chatwoot_contact_id = ct.chatwoot_contact_id AND p.deleted = 0 '
            . 'WHERE c.deleted = 0 AND c.chatwoot_account_id = :account '
            . 'AND c.last_activity_at >= UTC_TIMESTAMP() - INTERVAL ' . self::WINDOW_DAYS . ' DAY '
            . 'AND c.last_activity_at <= UTC_TIMESTAMP() - INTERVAL ' . Feed::SETTLE_MINUTES . ' MINUTE '
            . "AND COALESCE(ct.identifier, '') <> 'whatsapp.integration' "
            . "AND (p.party_class IS NULL OR p.party_class IN ('customer', 'partner')) "
            . 'AND NOT EXISTS (' . self::OPEN_DEAL_SQL . ') '
            . 'AND c.id NOT IN (SELECT z.target_id FROM catch_up_snooze z WHERE z.deleted = 0 AND z.user_id = :user '
            . "AND z.target_type = 'ChatwootConversation' AND z.snoozed_until > UTC_TIMESTAMP())" . $owner . ' '
            // Recent waits first, longest first; older waits next, most recent first; then quiet, then the rest.
            . 'ORDER BY CASE WHEN ' . self::RECENT_WAIT . ' THEN 0 WHEN ' . self::WAITING . ' THEN 1 '
            . 'WHEN c.last_activity_at < UTC_TIMESTAMP() - INTERVAL ' . Feed::FOLLOW_UP_AFTER_DAYS . ' DAY THEN 2 ELSE 3 END, '
            . 'CASE WHEN ' . self::RECENT_WAIT . ' THEN c.last_message_received_at END ASC, '
            . 'CASE WHEN ' . self::WAITING . ' THEN c.last_message_received_at END DESC, c.last_activity_at DESC, c.id '
            . 'LIMIT :limit OFFSET :offset'
        );
        foreach (['account' => $accountId, 'user' => $this->user->getId(), ...$params] as $name => $value) $query->bindValue($name, $value);
        $query->bindValue('limit', $limit, \PDO::PARAM_INT);
        $query->bindValue('offset', $offset, \PDO::PARAM_INT);
        $query->execute();
        $rows = [];
        foreach ($query->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $rows[(string) $row['id']] = ['owner' => $row['owner'], 'grp' => (int) $row['grp'], 'contact' => $row['contact']];
        }
        return $rows;
    }

    /**
     * The candidates the reader may open, in candidate order: the ChatwootConversation access filter decides
     * (inbox membership), as it does everywhere else in the CRM.
     *
     * @param list<string> $ids
     * @return array<string, Entity>
     */
    private function readable(array $ids): array
    {
        if (!$ids) return [];
        $query = $this->select->create()->from('ChatwootConversation')->withStrictAccessControl()->buildQueryBuilder()
            ->where(['id' => $ids])->build();
        $found = [];
        foreach ($this->em->getRDBRepository('ChatwootConversation')->clone($query)->find() as $conversation) $found[$conversation->getId()] = $conversation;
        $ordered = [];
        foreach ($ids as $id) if (isset($found[$id])) $ordered[$id] = $found[$id];
        return $ordered;
    }

    /** A conversation of the deck's account that the reader may open. */
    private function conversation(string $id, Entity $account): Entity
    {
        if (!preg_match('/^[a-zA-Z0-9_-]{1,36}$/D', $id)) throw new BadRequest('Invalid conversation.');
        $conversation = $this->em->getEntityById('ChatwootConversation', $id);
        if (!$conversation) throw new NotFound();
        if ($conversation->get('chatwootAccountId') !== $account->getId() || !$this->acl->checkEntityRead($conversation)) throw new Forbidden();
        if (!$this->access->allows($conversation)) throw new Forbidden();
        return $conversation;
    }

    private function shown(string $id, string $token): array
    {
        $snapshot = $this->cache->tryGet($this->snapshotKey($id));
        if (!$snapshot || $snapshot['expires'] < time() || !hash_equals($snapshot['token'], $token)) {
            throw new Conflict('Refresh this card before acting on it.');
        }
        return $snapshot;
    }

    private function owner(string $id): ?string
    {
        $query = $this->em->getPDO()->prepare('SELECT ' . Ownership::CONVERSATION_OWNER_SQL
            . ' FROM chatwoot_conversation c JOIN chatwoot_account a ON a.id = c.chatwoot_account_id WHERE c.id = :id');
        $query->execute(['id' => $id]);
        $owner = $query->fetchColumn();
        return is_string($owner) && $owner !== '' ? $owner : null;
    }

    private function candidate(Entity $conversation, ?string $ownerName, bool $group, DateTimeImmutable $now): array
    {
        $received = $conversation->get('lastMessageReceivedAt');
        $sent = $conversation->get('lastMessageSentAt');
        return [
            'id' => $conversation->getId(), 'type' => 'conversation',
            'name' => $conversation->get('contactDisplayName') ?: $conversation->get('name'),
            'contactName' => $conversation->get('contactDisplayName') ?: $conversation->get('name'),
            'amount' => null, 'amountCurrency' => null, 'stage' => null, 'group' => $group,
            'inboxName' => $conversation->get('inboxName'), 'ownerName' => $ownerName,
            'customerSpokeLast' => $received !== null && ($sent === null || $received > $sent),
            'quietDays' => Revision::quietDays($conversation->get('lastActivityAt'), $now),
            'sort' => Feed::sortKey($received, $sent, $conversation->get('lastActivityAt'), $now),
        ];
    }

    /** Day-stable facts the analysis decides on; exact times live in the messages. */
    private function facts(array $candidate, DateTimeImmutable $now): array
    {
        return [
            'conversation' => $candidate['name'], 'contactName' => $candidate['contactName'], 'group' => $candidate['group'],
            'inbox' => $candidate['inboxName'], 'deckOwnerName' => $candidate['ownerName'],
            'quietDays' => $candidate['quietDays'], 'lastSpeaker' => $candidate['customerSpokeLast'] ? 'customer' : 'business',
            'followUpAfterDays' => Feed::FOLLOW_UP_AFTER_DAYS,
            'today' => $now->format('Y-m-d'), 'timeZone' => $now->getTimezone()->getName(),
        ];
    }

    /** @param array<string> $ids @return array<string, string> */
    private function userNames(array $ids): array
    {
        if (!$ids) return [];
        $names = [];
        foreach ($this->em->getRDBRepository('User')->where(['id' => array_values(array_unique($ids))])->find() as $user) {
            $names[$user->getId()] = $user->get('name');
        }
        return $names;
    }

    private function authorize(): void
    {
        if ((!$this->user->isRegular() && !$this->user->isAdmin()) || !$this->acl->checkScope('ChatwootConversation', 'read')) throw new Forbidden();
    }

    private function snapshotKey(string $id): string
    {
        return 'catchUp/snapshots/v2/service/' . hash('sha256', $this->user->getId() . ':' . $id);
    }
}

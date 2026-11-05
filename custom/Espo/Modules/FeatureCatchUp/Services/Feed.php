<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCatchUp\Services;

use DateTimeImmutable;
use DateTimeZone;
use Espo\Core\Acl;
use Espo\Core\Api\Request;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Conflict;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Select\SelectBuilderFactory;
use Espo\Core\Utils\DataCache;
use Espo\Core\Utils\Metadata;
use Espo\Entities\User;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Em dia v2: every card comes from an open deal. The list returns candidates in a cheap order; the AI's
 * verdict on each deal's whole conversation decides whether a card appears (Summary::analyse).
 */
class Feed
{
    private const PAGE_SIZE = 20;
    /** Newest deal notes sent to the analysis, beside Transcript::LIMIT messages; the backend accepts at most 50 entries. */
    private const POST_LIMIT = 10;
    /** A quiet customer is worth chasing after this many days. Shadow data should tune it per stage. */
    public const FOLLOW_UP_AFTER_DAYS = 2;
    /**
     * A deal is analysed only once its conversation has been quiet this long. At Ferraz Veloso (2026-11-05) a
     * third of the analyses ran mid-conversation, were overtaken by the next message within two hours and
     * showed "customer waiting" cards for chats the team was answering.
     */
    public const SETTLE_MINUTES = 30;
    /**
     * A deal quiet this long has had its last check (the 30-day quiet step) and leaves the deck: 30 days plus
     * time for a deck to reach it. It comes back when a message moves it.
     */
    public const DORMANT_DAYS = 45;

    public function __construct(
        private EntityManager $em,
        private SelectBuilderFactory $select,
        private Acl $acl,
        private User $user,
        private Workspace $workspace,
        private Rollout $rollout,
        private Ownership $owners,
        private DealStates $states,
        private Verdicts $verdicts,
        private DataCache $cache,
        private Metadata $metadata,
        private Transcript $transcript,
    ) {}

    public function list(Request $request): array
    {
        $this->authorize();
        $tenantId = $this->workspace->resolve($request)['tenant']->getId();
        $now = Workspace::now($request);
        $cursor = (string) ($request->getQueryParam('cursor') ?? '');
        if ($cursor !== '' && !ctype_digit($cursor)) throw new BadRequest('Invalid cursor.');
        $offset = (int) $cursor;
        $limit = Workspace::limit($request, self::PAGE_SIZE);
        $ids = $this->candidateIds($tenantId, $offset, $limit);
        $deals = $this->readable($ids);
        $context = $this->context($deals, $now);
        $quiet = $this->verdicts->quiet('Opportunity', array_map(fn ($entry) => $entry['revision'], $context));
        $items = [];
        foreach ($deals as $id => $deal) {
            if (isset($quiet[$id])) continue;
            $items[] = $this->candidate($deal, $context[$id], $now);
        }
        return ['items' => $items, 'cursor' => count($ids) === $limit ? (string) ($offset + $limit) : null];
    }

    public function count(Request $request): array
    {
        $this->authorize();
        $tenantId = $this->workspace->resolve($request)['tenant']->getId();
        [$sql, $params] = $this->owners->clause();
        return ['count' => $this->verdicts->count($tenantId, $sql, $params, $this->user->getId())];
    }

    public function snapshot(string $id, Request $request): array
    {
        $this->authorize();
        ['tenant' => $tenant, 'policy' => $policy] = $this->workspace->resolve($request);
        $now = Workspace::now($request);
        $deal = $this->deal($id, $tenant->getId());
        if (!$this->states->load([$id])) $this->states->refresh([$id]);
        $context = $this->context([$id => $deal], $now)[$id];
        $conversations = $this->conversations($deal);
        ['evidence' => $messages, 'participants' => $participants] = $this->transcript->read($conversations, $now);
        $evidence = [...$messages, ...$this->posts($id, $now)];
        usort($evidence, fn ($a, $b) => [$a['ts'], $a['id']] <=> [$b['ts'], $b['id']]);
        $evidence = array_map(fn ($entry) => array_diff_key($entry, ['ts' => true]), $evidence);
        $scope = self::evidenceScope($evidence);
        $result = [
            ...$this->candidate($deal, $context, $now),
            'tenantId' => $tenant->getId(), 'catchUpPolicy' => $policy,
            'revision' => $context['revision'], 'evidenceScope' => $scope, 'evidence' => $evidence, 'participants' => $participants,
            'facts' => $this->facts($deal, $context, $now), 'nextAction' => $context['nextAction'],
            'conversation' => $this->target($conversations), 'closable' => $this->closable($deal),
        ];
        $result['snapshot'] = hash('sha256', json_encode([$id, $this->user->getId(), $context['revision'], $scope], JSON_THROW_ON_ERROR));
        $this->cache->store($this->snapshotKey($id), [
            'token' => $result['snapshot'], 'revision' => $context['revision'], 'expires' => time() + 86400,
        ]);
        return $result;
    }

    /** Acting on a card first confirms the deal hasn't moved since the card was shown. The deal's own tenant must be opted in. */
    public function review(string $id, string $token, Request $request): void
    {
        $this->authorize();
        $this->rollout->forOpportunity($id);
        $tenantId = (string) $this->em->getEntityById('Opportunity', $id)?->get('tenantId');
        $snapshot = $this->cache->tryGet($this->snapshotKey($id));
        if (!$snapshot || $snapshot['expires'] < time() || !hash_equals($snapshot['token'], $token)) {
            throw new Conflict('Refresh this card before acting on it.');
        }
        $deal = $this->deal($id, $tenantId);
        $now = Workspace::now($request);
        if ($this->context([$id => $deal], $now)[$id]['revision'] !== $snapshot['revision']) {
            throw new Conflict('This deal changed since the card was shown.');
        }
        $this->verdicts->resolve('Opportunity', $id);
    }

    /**
     * What a reader's evidence is, for sharing stored verdicts: the entries and who each counts as sent by. A
     * label someone corrects on a contact changes it, so the deal is judged again.
     *
     * @param list<array<string, mixed>> $evidence
     */
    public static function evidenceScope(array $evidence): string
    {
        return hash('sha256', json_encode(array_map(fn ($entry) => [$entry['id'], $entry['sentBy'] ?? null], $evidence), JSON_THROW_ON_ERROR));
    }

    /** An open deal of this tenant the reader may read. */
    public function deal(string $id, string $tenantId): Entity
    {
        if (!preg_match('/^[a-zA-Z0-9_-]{1,36}$/D', $id)) throw new BadRequest('Invalid deal.');
        $deal = $this->em->getEntityById('Opportunity', $id);
        if (!$deal) throw new NotFound();
        if ($deal->get('tenantId') !== $tenantId || !$this->acl->checkEntityRead($deal)) throw new Forbidden();
        return $deal;
    }

    /**
     * Open deals with a conversation in the reader's deck whose conversation has settled (quiet for
     * SETTLE_MINUTES) and isn't dormant (DORMANT_DAYS), ordered without scanning messages: the customer
     * waiting on us first (freshest first), then gone quiet past the follow-up threshold, then still moving,
     * then quiet for a month (their last check), then with no messages known yet.
     *
     * @return list<string>
     */
    private function candidateIds(string $tenantId, int $offset, int $limit): array
    {
        [$owner, $params] = $this->owners->clause();
        $waiting = 's.last_customer_at IS NOT NULL AND (s.last_business_at IS NULL OR s.last_customer_at > s.last_business_at)';
        $query = $this->em->getPDO()->prepare(
            'SELECT o.id FROM opportunity o LEFT JOIN catch_up_deal_state s ON s.id = o.id AND s.deleted = 0 '
            . "WHERE o.deleted = 0 AND o.tenant_id = :tenant AND o.status NOT IN ('Won','Lost') "
            . 'AND EXISTS (SELECT 1 FROM chatwoot_conversation_opportunity r WHERE r.opportunity_id = o.id AND r.deleted = 0) '
            . 'AND o.id NOT IN (SELECT z.target_id FROM catch_up_snooze z WHERE z.deleted = 0 AND z.user_id = :user '
            . "AND z.target_type = 'Opportunity' AND z.snoozed_until > UTC_TIMESTAMP()) "
            . 'AND (s.last_message_at IS NULL OR s.last_message_at <= UTC_TIMESTAMP() - INTERVAL ' . self::SETTLE_MINUTES . ' MINUTE)' . $owner . ' '
            . 'AND (s.last_message_at IS NULL OR s.last_message_at >= UTC_TIMESTAMP() - INTERVAL ' . self::DORMANT_DAYS . ' DAY) '
            . 'ORDER BY CASE WHEN s.last_message_at IS NULL THEN 5 '
            . "WHEN $waiting AND s.last_customer_at >= UTC_TIMESTAMP() - INTERVAL 30 DAY THEN 0 "
            . 'WHEN s.last_message_at < UTC_TIMESTAMP() - INTERVAL 30 DAY THEN 3 '
            . 'WHEN s.last_message_at < UTC_TIMESTAMP() - INTERVAL ' . self::FOLLOW_UP_AFTER_DAYS . ' DAY THEN 1 ELSE 2 END, '
            . "CASE WHEN $waiting THEN s.last_customer_at END DESC, o.amount DESC, s.last_message_at DESC, o.id "
            . 'LIMIT :limit OFFSET :offset'
        );
        foreach (['tenant' => $tenantId, 'user' => $this->user->getId(), ...$params] as $name => $value) $query->bindValue($name, $value);
        $query->bindValue('limit', $limit, \PDO::PARAM_INT);
        $query->bindValue('offset', $offset, \PDO::PARAM_INT);
        $query->execute();
        return array_map('strval', $query->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * The candidates this reader may read, in candidate order. The Opportunity ACL decides which; the deals are
     * then loaded whole, like snapshot() loads them, so both compute the same revision.
     *
     * @param list<string> $ids
     * @return array<string, Entity>
     */
    private function readable(array $ids): array
    {
        if (!$ids) return [];
        $query = $this->select->create()->from('Opportunity')->withStrictAccessControl()->buildQueryBuilder()
            ->select(['id'])->where(['id' => $ids])->build();
        $allowed = [];
        foreach ($this->em->getRDBRepository('Opportunity')->clone($query)->find() as $deal) $allowed[] = $deal->getId();
        if (!$allowed) return [];
        $found = [];
        foreach ($this->em->getRDBRepository('Opportunity')->where(['id' => $allowed])->find() as $deal) $found[$deal->getId()] = $deal;
        $ordered = [];
        foreach ($ids as $id) if (isset($found[$id])) $ordered[$id] = $found[$id];
        return $ordered;
    }

    /**
     * What each deal's revision and card are built from.
     *
     * @param array<string, Entity> $deals
     * @return array<string, array<string, mixed>>
     */
    private function context(array $deals, DateTimeImmutable $now): array
    {
        $ids = array_keys($deals);
        $states = $this->states->load($ids);
        $posts = $this->lastPosts($ids);
        $context = [];
        foreach ($deals as $id => $deal) {
            $state = $states[$id] ?? null;
            $nextAction = $this->nextAction($deal);
            $context[$id] = [
                'state' => $state, 'nextAction' => $nextAction,
                'revision' => Revision::of($deal, $state, $nextAction, $posts[$id] ?? null, $now),
                'ownerId' => $this->owners->deckOwnerId($deal->get('assignedUserId'), $state['staff_user_id'] ?? null),
            ];
        }
        $names = $this->userNames(array_filter(array_column($context, 'ownerId')));
        foreach ($context as $id => $entry) $context[$id]['ownerName'] = $names[$entry['ownerId']] ?? null;
        return $context;
    }

    private function candidate(Entity $deal, array $context, DateTimeImmutable $now): array
    {
        $state = $context['state'];
        $read = fn (string $field) => $this->acl->checkField('Opportunity', $field) ? $deal->get($field) : null;
        return [
            'id' => $deal->getId(), 'type' => 'opportunity', 'name' => $deal->get('name'),
            'amount' => $read('amount'), 'amountCurrency' => $read('amountCurrency'),
            'stage' => $this->acl->checkField('Opportunity', 'opportunityStage') ? $deal->get('opportunityStageName') : null,
            'contactName' => $this->acl->checkField('Opportunity', 'contact') ? $deal->get('contactName') : null,
            'ownerName' => $context['ownerName'],
            'customerSpokeLast' => $state !== null && $state['last_customer_at'] !== null
                && ($state['last_business_at'] === null || $state['last_customer_at'] > $state['last_business_at']),
            'quietDays' => Revision::quietDays($state['last_message_at'] ?? null, $now),
        ];
    }

    /** Day-stable facts the analysis decides on; exact times live in the messages. */
    private function facts(Entity $deal, array $context, DateTimeImmutable $now): array
    {
        $candidate = $this->candidate($deal, $context, $now);
        $next = $context['nextAction'];
        $created = $deal->get('createdAt') ? new DateTimeImmutable($deal->get('createdAt'), new DateTimeZone('UTC')) : null;
        $lastSpeaker = $context['state'] && $context['state']['last_message_at'] ? ($candidate['customerSpokeLast'] ? 'customer' : 'business') : null;
        return [
            'deal' => $deal->get('name'), 'stage' => $candidate['stage'], 'amount' => $candidate['amount'],
            'amountCurrency' => $candidate['amountCurrency'], 'contactName' => $candidate['contactName'],
            'deckOwnerName' => $candidate['ownerName'],
            'dealAgeDays' => $created ? max(0, intdiv($now->getTimestamp() - $created->getTimestamp(), 86400)) : null,
            'quietDays' => $candidate['quietDays'], 'lastSpeaker' => $lastSpeaker,
            'followUpAfterDays' => self::FOLLOW_UP_AFTER_DAYS,
            'aiFollowupActive' => $deal->get('followupStatus') === 'FollowupActive',
            'nextActionName' => $next['name'] ?? null,
            'nextActionDue' => $next ? ($next['dateEndDate'] ?? (!empty($next['dateEnd']) ? $this->local($next['dateEnd'], $now) : null)) : null,
            'nextActionLate' => $next !== null && $next['pending'] && $this->late($next, $now),
            'today' => $now->format('Y-m-d'), 'timeZone' => $now->getTimezone()->getName(),
        ];
    }

    /**
     * Linked conversations the reader may read, keyed by CRM id.
     *
     * @return array<string, Entity>
     */
    private function conversations(Entity $deal): array
    {
        if (!$this->acl->checkScope('ChatwootConversation', 'read')) return [];
        $found = [];
        $related = $this->em->getRDBRepository('Opportunity')->getRelation($deal, 'chatwootConversations')->find();
        foreach ($related as $conversation) {
            if ($this->acl->checkEntityRead($conversation)) $found[$conversation->getId()] = $conversation;
        }
        return $found;
    }

    /** Where Enviar posts: the conversation the customer last wrote in. */
    private function target(array $conversations): ?array
    {
        $best = null;
        foreach ($conversations as $conversation) {
            $key = [$conversation->get('lastMessageReceivedAt') ?? '', $conversation->get('lastActivityAt') ?? ''];
            if (!$best || $key > $best[0]) $best = [$key, $conversation];
        }
        if (!$best) return null;
        $conversation = $best[1];
        $account = $conversation->get('chatwootAccountId') ? $this->em->getEntityById('ChatwootAccount', $conversation->get('chatwootAccountId')) : null;
        if (!$conversation->get('chatwootConversationId') || !$account) return null;
        return [
            'conversationId' => (int) $conversation->get('chatwootConversationId'),
            'accountId' => (int) $account->get('chatwootAccountId'),
            'inboxName' => $conversation->get('inboxName'),
        ];
    }

    private function posts(string $id, DateTimeImmutable $now): array
    {
        if (!$this->acl->checkScope('Opportunity', 'stream')) return [];
        $notes = $this->em->getRDBRepository('Note')->where(['parentType' => 'Opportunity', 'parentId' => $id, 'type' => 'Post'])
            ->order('createdAt', 'DESC')->limit(0, self::POST_LIMIT)->find();
        $evidence = [];
        foreach ($notes as $note) {
            if (!$this->acl->checkEntityRead($note)) continue;
            $timestamp = (new DateTimeImmutable((string) $note->get('createdAt'), new DateTimeZone('UTC')))->getTimestamp();
            $evidence[] = [
                'id' => $note->getId(), 'text' => mb_substr(strip_tags((string) $note->get('post')), 0, 4000),
                'at' => Transcript::at($timestamp, $now), 'ts' => $timestamp, 'kind' => 'post', 'author' => $note->get('createdByName'),
                'sentBy' => 'internal', 'context' => false, 'conversationId' => null, 'accountId' => null, 'messageId' => null,
            ];
        }
        return $evidence;
    }

    /** @param list<string> $ids @return array<string, string> newest Post time per deal */
    private function lastPosts(array $ids): array
    {
        if (!$ids) return [];
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $query = $this->em->getPDO()->prepare(
            "SELECT parent_id, MAX(created_at) FROM note WHERE deleted = 0 AND parent_type = 'Opportunity' AND type = 'Post' "
            . "AND parent_id IN ($marks) GROUP BY parent_id"
        );
        $query->execute(array_values($ids));
        return $query->fetchAll(\PDO::FETCH_KEY_PAIR);
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

    /** Whether the deal's funnel has an active won (100%) and lost (0%) stage to move it to. */
    private function closable(Entity $deal): array
    {
        $result = ['won' => false, 'lost' => false];
        if (!$deal->get('funnelId') || !$this->acl->checkEntityEdit($deal)) return $result;
        $query = $this->em->getPDO()->prepare(
            'SELECT probability FROM opportunity_stage WHERE deleted = 0 AND is_active = 1 AND funnel_id = :funnel AND probability IN (0, 100)'
        );
        $query->execute(['funnel' => $deal->get('funnelId')]);
        foreach ($query->fetchAll(\PDO::FETCH_COLUMN) as $probability) $result[(int) $probability === 100 ? 'won' : 'lost'] = true;
        return $result;
    }

    /** The selected next step, when this user may read it. */
    private function nextAction(Entity $deal): ?array
    {
        $type = $deal->get('nextActionType');
        $id = $deal->get('nextActionId');
        if (!$this->acl->checkField('Opportunity', 'nextActionId') || !in_array($type, ['Task', 'Call', 'Meeting'], true) || !$id ||
            !$this->acl->checkScope($type, 'read')) return null;
        $activity = $this->em->getEntityById($type, $id);
        if (!$activity || !$this->acl->checkEntityRead($activity)) return null;
        $result = ['id' => $id, 'type' => $type];
        foreach (['name', 'status', 'dateEnd', 'dateEndDate'] as $field) {
            if ($this->acl->checkField($type, $field === 'dateEndDate' ? 'dateEnd' : $field)) $result[$field] = $activity->get($field);
        }
        // Same pending rule as choosing a next action (RecordNextAction::checkPending).
        $pending = $type === 'Task'
            ? !in_array($activity->get('status'), $this->metadata->get(['entityDefs', 'Task', 'fields', 'status', 'notActualOptions']) ?? [], true)
            : in_array($activity->get('status'), $this->metadata->get(['scopes', $type, 'activityStatusList']) ?? [], true);
        return $result + ['pending' => $pending];
    }

    private function late(array $activity, DateTimeImmutable $now): bool
    {
        if (!empty($activity['dateEndDate'])) return $activity['dateEndDate'] < $now->format('Y-m-d');
        return !empty($activity['dateEnd']) && $activity['dateEnd'] < $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private function local(string $utc, DateTimeImmutable $now): string
    {
        return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone($now->getTimezone())->format('Y-m-d H:i');
    }

    private function authorize(): void
    {
        if ((!$this->user->isRegular() && !$this->user->isAdmin()) ||
            !$this->acl->checkScope('Opportunity', 'read') || !$this->acl->checkField('Opportunity', 'name')) throw new Forbidden();
    }

    private function snapshotKey(string $id): string
    {
        return 'catchUp/snapshots/v2/' . hash('sha256', $this->user->getId() . ':' . $id);
    }
}

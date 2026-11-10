<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCatchUp\Services;

use DateTimeImmutable;
use DateTimeZone;
use Espo\Core\Acl;
use Espo\Core\Api\Request;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Select\SelectBuilderFactory;
use Espo\Entities\User;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * The Agenda tab (gtm/05 §16): what comes due in the next days and isn't a card yet, read from what is already
 * stored, with no analysis and no AI cost:
 * - loops whose time hasn't come (`loop`), from each subject's record built from conversations the reader may open;
 * - a deal's pending next step, Task, Call or Meeting (`step`);
 * - the AI follow-up armed on a deal (`ai`).
 * Same reach as the deck: deals and conversations in the reader's deck (Ownership), the CRM ACL, and only what
 * Chatwoot lets the reader open (ChatwootAccess); a deal shows only with a conversation the reader may open.
 */
class Agenda
{
    public const DAYS = 7;
    /** Items returned, earliest first. */
    private const LIMIT = 60;
    /** Subjects read per source before the access checks. */
    private const SCAN = 200;
    private const STEP_TABLES = ['Task' => 'task', 'Call' => '`call`', 'Meeting' => 'meeting'];

    public function __construct(
        private EntityManager $em,
        private SelectBuilderFactory $select,
        private Acl $acl,
        private User $user,
        private Workspace $workspace,
        private Ownership $owners,
        private Feed $feed,
        private Records $records,
        private ChatwootAccess $access,
    ) {}

    /** @return array{items: list<array<string, mixed>>, days: int} */
    public function list(Request $request): array
    {
        if (!$this->user->isRegular() && !$this->user->isAdmin()) throw new Forbidden();
        ['tenant' => $tenant, 'account' => $account] = $this->workspace->resolve($request);
        $now = Workspace::now($request);
        $until = $now->modify('+' . self::DAYS . ' days');
        $items = [...$this->dealItems($tenant->getId(), $now, $until), ...$this->conversationItems($tenant->getId(), $account, $now, $until)];
        usort($items, fn ($a, $b) => [$a['ts'], $a['subject']['id']] <=> [$b['ts'], $b['subject']['id']]);
        return ['items' => array_map(fn ($item) => array_diff_key($item, ['ts' => true]), array_slice($items, 0, self::LIMIT)), 'days' => self::DAYS];
    }

    /** @return list<array<string, mixed>> */
    private function dealItems(string $tenantId, DateTimeImmutable $now, DateTimeImmutable $until): array
    {
        if (!$this->acl->checkScope('Opportunity', 'read') || !$this->acl->checkField('Opportunity', 'name')) return [];
        [$owner, $params] = $this->owners->clause();
        $base = 'FROM opportunity o LEFT JOIN catch_up_deal_state s ON s.id = o.id AND s.deleted = 0 '
            . "WHERE o.deleted = 0 AND o.tenant_id = :tenant AND o.status NOT IN ('Won','Lost') $owner";
        $window = ['tenant' => $tenantId, ...$params];
        $utc = fn (DateTimeImmutable $at) => $at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        $loopIds = $this->ids('SELECT DISTINCT o.id FROM catch_up_record r JOIN opportunity o ON o.id = r.subject_id '
            . 'LEFT JOIN catch_up_deal_state s ON s.id = o.id AND s.deleted = 0 '
            . "WHERE r.deleted = 0 AND r.subject_type = 'Opportunity' AND r.tenant_id = :tenant AND r.due_ts IS NOT NULL AND r.due_ts <= :until "
            . "AND o.deleted = 0 AND o.tenant_id = :tenant2 AND o.status NOT IN ('Won','Lost') $owner",
            [...$window, 'tenant2' => $tenantId, 'until' => $until->getTimestamp()]);
        // Native prepared statements: a placeholder can't repeat, so each activity table gets its own.
        $dated = [];
        $stepParams = $window;
        foreach (self::STEP_TABLES as $type => $table) {
            $key = strtolower($type);
            $allDay = $type === 'Call' ? '' : " OR x.date_end_date BETWEEN :{$key}FromDate AND :{$key}UntilDate";
            $dated[] = "(o.next_action_type = '$type' AND EXISTS (SELECT 1 FROM $table x WHERE x.id = o.next_action_id AND x.deleted = 0 "
                . "AND (x.date_end BETWEEN :{$key}From AND :{$key}To$allDay)))";
            $stepParams += ["{$key}From" => $utc($now), "{$key}To" => $utc($until)]
                + ($allDay ? ["{$key}FromDate" => $now->format('Y-m-d'), "{$key}UntilDate" => $until->format('Y-m-d')] : []);
        }
        $stepIds = $this->ids("SELECT o.id $base AND o.next_action_id IS NOT NULL AND (" . implode(' OR ', $dated) . ')', $stepParams);
        $aiIds = $this->ids("SELECT o.id $base AND o.followup_status = 'FollowupActive' AND o.followup_timer BETWEEN :from AND :to",
            [...$window, 'from' => $utc($now), 'to' => $utc($until)]);
        $deals = $this->readable('Opportunity', array_values(array_unique([...$loopIds, ...$stepIds, ...$aiIds])));
        // Only deals with a conversation the reader may open in Chatwoot; this also fills ChatwootAccess for the records below.
        $deals = array_intersect_key($deals, $this->access->dealsWithOpenConversation(array_keys($deals)));
        $items = [];
        foreach ($deals as $id => $deal) {
            $next = $this->feed->nextAction($deal);
            $subject = ['type' => 'opportunity', 'id' => $id, 'name' => (string) $deal->get('name')];
            $schedule = ['nextAction' => $next ? ['id' => $next['id'], 'type' => $next['type']] : null,
                'canSchedule' => !($next['pending'] ?? false) && $this->acl->checkEntityEdit($deal)];
            if (in_array($id, $loopIds, true)) {
                $conversations = $this->feed->conversations($deal);
                $record = $conversations ? $this->records->load('Opportunity', $id, Records::scope(array_keys($conversations))) : null;
                foreach ($this->upcoming($record, $now, $until) as $loop) $items[] = ['kind' => 'loop', 'subject' => $subject, ...$loop, ...$schedule];
            }
            if ($next && $next['pending'] && in_array($id, $stepIds, true)) {
                $at = $this->stepTime($next, $now);
                if ($at && $at[0] > $now->getTimestamp() && $at[0] <= $until->getTimestamp()) {
                    $items[] = ['kind' => 'step', 'subject' => $subject, 'side' => 'ours', 'stepType' => $next['type'],
                        'text' => (string) ($next['name'] ?? ''), 'dueAt' => $at[1], 'allDay' => $at[2], 'ts' => $at[0]];
                }
            }
            if (in_array($id, $aiIds, true) && $this->acl->checkField('Opportunity', 'followupTimer') && $deal->get('followupTimer')) {
                $at = new DateTimeImmutable((string) $deal->get('followupTimer'), new DateTimeZone('UTC'));
                $items[] = ['kind' => 'ai', 'subject' => $subject, 'side' => 'ours', 'text' => null,
                    'dueAt' => $at->setTimezone($now->getTimezone())->format(DATE_ATOM), 'allDay' => false, 'ts' => $at->getTimestamp()];
            }
        }
        return $items;
    }

    /** Atendimento: conversations of the deck's account without an open deal. @return list<array<string, mixed>> */
    private function conversationItems(string $tenantId, Entity $account, DateTimeImmutable $now, DateTimeImmutable $until): array
    {
        if (!$this->acl->checkScope('ChatwootConversation', 'read')) return [];
        [$owner, $params] = $this->owners->conversationClause();
        $ids = $this->ids('SELECT DISTINCT c.id FROM catch_up_record r JOIN chatwoot_conversation c ON c.id = r.subject_id AND c.deleted = 0 '
            . 'JOIN chatwoot_account a ON a.id = c.chatwoot_account_id '
            . "WHERE r.deleted = 0 AND r.subject_type = 'ChatwootConversation' AND r.tenant_id = :tenant AND r.due_ts IS NOT NULL "
            . 'AND r.due_ts <= :until AND c.chatwoot_account_id = :account AND NOT EXISTS (' . ServiceFeed::OPEN_DEAL_SQL . ")$owner",
            ['tenant' => $tenantId, 'until' => $until->getTimestamp(), 'account' => $account->getId(), ...$params]);
        $items = [];
        foreach ($this->access->filter($this->readable('ChatwootConversation', $ids)) as $id => $conversation) {
            $subject = ['type' => 'conversation', 'id' => $id, 'name' => (string) ($conversation->get('contactDisplayName') ?: $conversation->get('name'))];
            $target = ['conversation' => ['conversationId' => (int) $conversation->get('chatwootConversationId'),
                'accountId' => (int) $account->get('chatwootAccountId')]];
            foreach ($this->upcoming($this->records->load('ChatwootConversation', $id, Records::scope([$id])), $now, $until) as $loop) {
                $items[] = ['kind' => 'loop', 'subject' => $subject, ...$loop, ...$target, 'nextAction' => null, 'canSchedule' => false];
            }
        }
        return $items;
    }

    /** Open loops of a record whose time is after now and within the window. @return list<array<string, mixed>> */
    private function upcoming(?array $record, DateTimeImmutable $now, DateTimeImmutable $until): array
    {
        $loops = [];
        foreach (Loops::open($record) as $loop) {
            $due = Loops::due($loop);
            if ($due === null || $due <= $now->getTimestamp() || $due > $until->getTimestamp()) continue;
            $loops[] = ['side' => $loop['side'], 'text' => mb_substr((string) $loop['text'], 0, 200), 'dueAt' => (string) $loop['dueAt'],
                'allDay' => false, 'ts' => $due];
        }
        return $loops;
    }

    /** @return ?array{int, string, bool} Unix time, the reader's local time (or date when all day), all day */
    private function stepTime(array $next, DateTimeImmutable $now): ?array
    {
        if (!empty($next['dateEndDate'])) {
            $day = new DateTimeImmutable((string) $next['dateEndDate'] . ' 23:59:59', $now->getTimezone());
            return [$day->getTimestamp(), (string) $next['dateEndDate'], true];
        }
        if (empty($next['dateEnd'])) return null;
        $at = new DateTimeImmutable((string) $next['dateEnd'], new DateTimeZone('UTC'));
        return [$at->getTimestamp(), $at->setTimezone($now->getTimezone())->format(DATE_ATOM), false];
    }

    /** @return list<string> */
    private function ids(string $sql, array $params): array
    {
        $query = $this->em->getPDO()->prepare($sql . ' LIMIT ' . self::SCAN);
        $query->execute($params);
        return array_map('strval', $query->fetchAll(\PDO::FETCH_COLUMN));
    }

    /**
     * The records of $ids the reader may read, as the deck reads them (the strict access filter), loaded whole.
     *
     * @param list<string> $ids
     * @return array<string, Entity>
     */
    private function readable(string $entityType, array $ids): array
    {
        if (!$ids) return [];
        $query = $this->select->create()->from($entityType)->withStrictAccessControl()->buildQueryBuilder()->where(['id' => $ids])->build();
        $found = [];
        foreach ($this->em->getRDBRepository($entityType)->clone($query)->find() as $entity) $found[$entity->getId()] = $entity;
        return $found;
    }
}

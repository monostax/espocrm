<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Services;

use DateTimeImmutable;
use DateTimeZone;
use Espo\Core\Acl;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Record\ServiceContainer;
use Espo\Core\Select\SearchParams;
use Espo\Core\Select\SelectBuilderFactory;
use Espo\Core\Utils\Metadata;
use Espo\Entities\User;
use Espo\Modules\Chatwoot\Tools\Activities\Access;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Query\SelectBuilder;
use Espo\ORM\Query\UnionBuilder;

class ActivityInbox
{
    public const DATES = ['overdue', 'today', 'tomorrow', 'thisWeek', 'nextWeek', 'thisMonth', 'nextMonth', 'noDate'];

    public function __construct(
        private EntityManager $em,
        private Acl $acl,
        private User $user,
        private Metadata $metadata,
        private Access $access,
        private ActivityDiscussion $discussion,
        private ServiceContainer $services,
        private SelectBuilderFactory $select,
    ) {}

    private function types(array $filters): array
    {
        $types = empty($filters['type']) ? Access::TYPES : [$this->access->type($filters['type'])];
        return array_values(array_filter($types, fn ($type) => $this->acl->checkScope($type, 'read') && !$this->metadata->get(['scopes', $type, 'disabled'])));
    }

    public function query(string $type, Entity $tenant, array $filters): SelectBuilder
    {
        $query = $this->access->query($type, $tenant, ($filters['read_status'] ?? '') === 'unread' || ($filters['view'] ?? '') === 'mentions');
        if (($filters['assignee_tab'] ?? '') === 'me') $query->where(['assignedUserId' => $this->user->getId()]);
        if (($filters['assignee_tab'] ?? '') === 'unassigned') $query->where(['assignedUserId' => null]);
        if (!empty($filters['assigned_user'])) $query->where(['assignedUserId' => $filters['assigned_user']]);
        if (!empty($filters['status'])) $query->where(['status' => $filters['status']]);
        if (!empty($filters['search'])) $query->where(['name*' => '%' . trim($filters['search']) . '%']);
        if (!empty($filters['due'])) $this->applyDue($query, $type, $filters['due'], $filters['timeZone'] ?? 'UTC');
        if (($filters['read_status'] ?? '') === 'unread' || ($filters['view'] ?? '') === 'mentions') {
            if (!$this->acl->checkScope($type, 'stream')) return $query->where(['id' => null]);
            $this->discussion->applyFilter($query, $type, ($filters['read_status'] ?? '') === 'unread');
        }
        return $query;
    }

    private function applyDue(SelectBuilder $query, string $type, string $due, string $zone): void
    {
        if (!in_array($due, self::DATES, true)) throw new BadRequest('Invalid due date filter.');
        try { $now = new DateTimeImmutable('now', new DateTimeZone($zone)); }
        catch (\Exception) { throw new BadRequest('Invalid time zone.'); }
        $today = $now->setTime(0, 0);
        $dateOnly = $type !== 'Call';
        if ($due === 'noDate') {
            $query->where(['dateEnd' => null] + ($dateOnly ? ['dateEndDate' => null] : []));
            return;
        }
        $active = $this->metadata->get(['scopes', $type, 'activityStatusList']) ?? ($type === 'Task' ? ['Not Started', 'Started'] : ['Planned']);
        $query->where(['status' => $active]);
        if ($due === 'overdue') {
            $clauses = [['dateEnd<' => $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s')] + ($dateOnly ? ['dateEndDate' => null] : [])];
            if ($dateOnly) $clauses[] = ['dateEndDate<' => $today->format('Y-m-d')];
            $query->where(['OR' => $clauses]);
            return;
        }
        [$start, $end] = match ($due) {
            'today' => [$today, $today->modify('+1 day')],
            'tomorrow' => [$today->modify('+1 day'), $today->modify('+2 days')],
            'thisWeek' => [$today->modify('monday this week'), $today->modify('monday next week')],
            'nextWeek' => [$today->modify('monday next week'), $today->modify('monday next week')->modify('+1 week')],
            'thisMonth' => [$today->modify('first day of this month'), $today->modify('first day of next month')],
            'nextMonth' => [$today->modify('first day of next month'), $today->modify('first day of next month')->modify('+1 month')],
        };
        $clauses = [['dateEnd>=' => $start->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'), 'dateEnd<' => $end->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s')] + ($dateOnly ? ['dateEndDate' => null] : [])];
        if ($dateOnly) $clauses[] = ['dateEndDate>=' => $start->format('Y-m-d'), 'dateEndDate<' => $end->format('Y-m-d')];
        $query->where(['OR' => $clauses]);
    }

    public function list(Entity $tenant, array $filters): object
    {
        $offset = max(0, (int) ($filters['offset'] ?? 0));
        $limit = min(100, max(1, (int) ($filters['maxSize'] ?? 25)));
        $sort = $filters['sort'] ?? 'dateEnd';
        if (!in_array($sort, ['dateEnd', 'createdAt', 'modifiedAt', 'name'], true)) throw new BadRequest('Invalid sort.');
        $order = ($filters['order'] ?? 'asc') === 'desc' ? 'DESC' : 'ASC';
        $union = UnionBuilder::create()->all();
        $types = $this->types($filters);
        $total = 0;
        foreach ($types as $type) {
            $query = $this->query($type, $tenant, $filters);
            $repo = $this->em->getRDBRepository($type);
            $total += $repo->clone($query->build())->count();
            $expression = $sort === 'dateEnd' && $type !== 'Call' ? 'COALESCE:(dateEndDate, dateEnd)' : $sort;
            // Sort the union in the database: PHP string ordering disagrees with
            // database collations for names, invalidating merged prefix pagination.
            $union->query($query->select(['id', ['VALUE:' . $type, 'type'], [$expression, 'inboxSort']])->order([])->build());
        }
        $page = $types ? $this->em->getQueryExecutor()->execute(
            $union->order([['inboxSort', $order], ['type', 'ASC'], ['id', 'ASC']])->limit($offset, $limit)->build()
        )->fetchAll(\PDO::FETCH_ASSOC) : [];
        $records = [];
        foreach ($this->types($filters) as $type) {
            $ids = array_column(array_filter($page, fn ($row) => $row['type'] === $type), 'id');
            if (!$ids) continue;
            $items = $this->services->get($type)->find(SearchParams::fromRaw(['maxSize' => $limit, 'where' => [['type' => 'in', 'attribute' => 'id', 'value' => $ids]]]))->getCollection();
            $streamIds = array_map(fn ($e) => $e->getId(), [...$this->em->getRDBRepository($type)->clone(
                $this->access->query($type, $tenant, true)->select('id')->where(['id' => $ids])->build()
            )->find()]);
            $states = $this->discussion->states($type, $streamIds);
            $previews = [];
            if ($streamIds) {
                $latest = SelectBuilder::create()->from('Note', 'latestActivityPost')->select([['MAX:number', 'lastNumber']])->where([
                    'parentType' => $type, 'parentId:' => 'note.parentId', 'type' => 'Post', 'opportunityPostDeleted' => false,
                ])->build();
                foreach ($this->em->getRDBRepository('Note')->select(['id', 'parentId', 'post', 'createdAt', 'createdByName'])->where([
                    'parentType' => $type, 'parentId' => $streamIds, 'number=s' => $latest,
                ])->find() as $post) {
                    $previews[$post->get('parentId')] = $post->getValueMap();
                }
            }
            foreach ($items as $item) {
                $row = $this->present($item);
                $row->readState = $states[$item->getId()] ?? null;
                $row->lastPost = $previews[$item->getId()] ?? null;
                $records[$type . ':' . $item->getId()] = $row;
            }
        }
        return (object) ['list' => array_values(array_filter(array_map(fn ($row) => $records[$row['type'] . ':' . $row['id']] ?? null, $page))), 'total' => $total, 'hasMore' => $offset + $limit < $total];
    }

    public function counts(Entity $tenant, array $filters): object
    {
        // Counts describe sidebar destinations, retaining only the global assignee scope.
        $base = array_intersect_key($filters, array_flip(['assignee_tab', 'timeZone']));
        $result = ['all' => 0, 'unread' => 0, 'mentions' => 0, 'types' => [], 'status' => [], 'users' => [], 'due' => []];
        foreach ($this->types([]) as $type) {
            $repo = $this->em->getRDBRepository($type);
            if (($filters['railOnly'] ?? '') === 'true') {
                $result['unread'] += $repo->clone($this->query($type, $tenant, ['read_status' => 'unread'])->build())->count();
                continue;
            }
            $query = $this->query($type, $tenant, $base);
            $count = $repo->clone($query->build())->count();
            $result['all'] += $count;
            $result['types'][$type] = $count;
            foreach (['status' => 'status', 'users' => 'assignedUserId'] as $key => $field) {
                $groupQuery = $key === 'users' ? $this->query($type, $tenant, []) : clone $query;
                $grouped = $groupQuery->select([$field, ['COUNT:id', 'count']])->group($field)->build();
                foreach ($this->em->getQueryExecutor()->execute($grouped)->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                    if ($row[$field]) $result[$key][$row[$field]] = ($result[$key][$row[$field]] ?? 0) + (int) $row['count'];
                }
            }
            foreach (['unread' => ['read_status' => 'unread'], 'mentions' => ['view' => 'mentions']] as $key => $filter) {
                $result[$key] += $repo->clone($this->query($type, $tenant, $base + $filter)->build())->count();
            }
            foreach (self::DATES as $due) {
                $result['due'][$due] = ($result['due'][$due] ?? 0) + $repo->clone($this->query($type, $tenant, $base + ['due' => $due])->build())->count();
            }
        }
        $result['ownerNames'] = [];
        foreach ($this->acl->checkScope('User', 'read') ? array_chunk(array_keys($result['users']), 100) : [] as $ids) {
            $owners = $this->services->get('User')->find(SearchParams::fromRaw([
                'select' => ['id', 'name'], 'maxSize' => 100,
                'where' => [['type' => 'in', 'attribute' => 'id', 'value' => $ids]],
            ]))->getCollection();
            foreach ($owners as $owner) $result['ownerNames'][$owner->getId()] = $owner->get('name');
        }
        return (object) $result;
    }

    public function present(Entity $entity): object
    {
        return (object) ((array) $entity->getValueMap() + [
            'entityType' => $entity->getEntityType(),
            'canEdit' => $this->acl->checkEntityEdit($entity), 'canDelete' => $this->acl->checkEntityDelete($entity),
            'canStream' => $this->acl->checkEntity($entity, 'stream') && $this->acl->checkScope('Note', 'read'),
            'canPost' => $this->acl->checkEntity($entity, 'stream') && $this->acl->checkScope('Note', 'create') && $this->acl->checkScope('Note', 'read'),
        ]);
    }

    public function conversations(Entity $entity): array
    {
        if (!$this->acl->checkScope('ChatwootConversation', 'read') ||
            in_array('chatwootConversations', $this->acl->getScopeForbiddenFieldList($entity->getEntityType()), true)) return [];
        return $this->services->get($entity->getEntityType())->findLinked($entity->getId(), 'chatwootConversations', SearchParams::fromRaw([
            'maxSize' => 100, 'select' => ['id', 'name', 'chatwootConversationId', 'chatwootAccountIdExternal'],
        ]))->getValueMapList();
    }

    public function metadata(): object
    {
        $result = [];
        $fields = ['name', 'status', 'priority', 'direction', 'description', 'dateStart', 'dateEnd', 'isAllDay', 'parent', 'assignedUser', 'teams', 'users', 'contacts', 'leads', 'reminders', 'duration'];
        foreach ($this->types([]) as $type) {
            $defs = $this->metadata->get(['entityDefs', $type, 'fields']) ?? [];
            $hidden = $this->acl->getScopeForbiddenFieldList($type);
            $readonly = $this->acl->getScopeForbiddenFieldList($type, 'edit');
            $visible = [];
            foreach ($defs as $name => $def) {
                if ((!in_array($name, $fields, true) && !($def['isCustom'] ?? false)) || in_array($name, $hidden, true)) continue;
                $visible[$name] = array_intersect_key($def, array_flip(['type', 'options', 'required', 'readOnly', 'readOnlyAfterCreate', 'entityList', 'default', 'maxLength']));
                if (in_array($name, $readonly, true)) $visible[$name]['readOnly'] = true;
                if (is_string($visible[$name]['default'] ?? null) && str_starts_with($visible[$name]['default'], 'javascript:')) unset($visible[$name]['default']);
            }
            $result[$type] = ['fields' => $visible, 'canCreate' => $this->acl->checkScope($type, 'create'),
                'completedStatuses' => $this->metadata->get(['scopes', $type, 'completedStatusList']) ?? ['Completed'],
                'activeStatuses' => $this->metadata->get(['scopes', $type, 'activityStatusList']) ?? ['Not Started', 'Started']];
        }
        return (object) $result;
    }

    public function options(Entity $tenant, string $type, string $search): object
    {
        if (!in_array($type, ['User', 'Team', 'Account', 'Contact', 'Lead', 'Opportunity', 'Case', 'ChatwootConversation'], true)) throw new BadRequest('Invalid option type.');
        $query = $this->select->create()->from($type)->withStrictAccessControl()->buildQueryBuilder()->select('id')->limit(0, 100)->order('name');
        if ($type === 'Team') $query->where(['id' => $this->access->teamIds($tenant)]);
        elseif ($type === 'ChatwootConversation') $query->join('chatwootAccount')->where(['chatwootAccount.tenantId' => $tenant->getId()]);
        elseif ($this->em->getDefs()->getEntity($type)->hasAttribute('tenantId')) $query->where(['tenantId' => $tenant->getId()]);
        else $query->join('teams', 'workspaceTeam')->where(['workspaceTeam.id' => $this->access->teamIds($tenant)])->distinct();
        if ($type === 'User') $query->where(['isActive' => true, 'type' => ['regular', 'admin', 'super-admin']]);
        if ($search !== '') $query->where(['name*' => '%' . $search . '%']);
        $ids = array_map(fn ($e) => $e->getId(), [...$this->em->getRDBRepository($type)->clone($query->build())->find()]);
        if (!$ids) return (object) ['list' => []];
        $list = $this->services->get($type)->find(SearchParams::fromRaw(['select' => ['id', 'name'], 'maxSize' => 100, 'orderBy' => 'name', 'where' => [['type' => 'in', 'attribute' => 'id', 'value' => $ids]]]))->getValueMapList();
        return (object) ['list' => $list];
    }
}

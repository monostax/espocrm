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
use Espo\Modules\Global\Tools\CrmTags;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Query\Part\Expression as Expr;
use Espo\ORM\Query\SelectBuilder;
use Espo\ORM\Query\UnionBuilder;

class ActivityInbox
{
    public const DATES = ['overdue', 'today', 'tomorrow', 'upcoming', 'noDate'];

    public function __construct(
        private EntityManager $em,
        private Acl $acl,
        private User $user,
        private Metadata $metadata,
        private Access $access,
        private ActivityDiscussion $discussion,
        private ServiceContainer $services,
        private SelectBuilderFactory $select,
        private CrmTags $tags,
    ) {}

    private function types(array $filters): array
    {
        $types = empty($filters['type']) ? Access::TYPES : [$this->access->type($filters['type'])];
        return array_values(array_filter($types, fn ($type) => $this->acl->checkScope($type, 'read') && !$this->metadata->get(['scopes', $type, 'disabled'])));
    }

    private function finishedStatuses(string $type): array
    {
        return array_values(array_unique(array_merge(
            $this->metadata->get(['scopes', $type, 'completedStatusList']) ?? ($type === 'Task' ? ['Completed'] : ['Held']),
            $this->metadata->get(['scopes', $type, 'canceledStatusList']) ?? ($type === 'Task' ? ['Canceled'] : ['Not Held']),
        )));
    }

    public function query(string $type, Entity $tenant, array $filters): SelectBuilder
    {
        $query = $this->access->query($type, $tenant, ($filters['read_status'] ?? '') === 'unread' || ($filters['view'] ?? '') === 'mentions');
        if (($filters['assignee_tab'] ?? '') === 'me') $query->where(['assignedUserId' => $this->user->getId()]);
        if (($filters['assignee_tab'] ?? '') === 'unassigned') $query->where(['assignedUserId' => null]);
        if (!empty($filters['assigned_user'])) $query->where(['assignedUserId' => $filters['assigned_user']]);
        $status = $filters['status'] ?? '';
        if ($status === 'Open') $query->where(['OR' => [['status!=' => $this->finishedStatuses($type)], ['status' => null]]]);
        elseif ($status === 'Finished') $query->where(['status' => $this->finishedStatuses($type)]);
        elseif ($status !== '') $query->where(['status' => $status]);
        if (!empty($filters['stage'])) $query->where(['status' => $filters['stage']]);
        if (!empty($filters['tag'])) {
            if (!$this->acl->checkField($type, 'tags') || !$this->acl->checkScope('CrmTag', 'read')) return $query->where(['id' => null]);
            $tag = $this->tags->query()->where(['id' => $filters['tag'], 'tenantId' => $tenant->getId()])->select(['id'])->build();
            $tagged = SelectBuilder::create()->from($type)->select(['id'])->join('tags', 'crmTag')->where(['crmTag.id=s' => $tag])->build();
            $query->where(['id=s' => $tagged]);
        }
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
        if ($due === 'overdue' || $due === 'today') {
            // Today includes every pending deadline before the next local day.
            $end = $due === 'today' ? $today->modify('+1 day') : $now;
            $clauses = [['dateEnd<' => $end->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s')] + ($dateOnly ? ['dateEndDate' => null] : [])];
            if ($dateOnly) $clauses[] = ['dateEndDate<' => $end->format('Y-m-d')];
            $query->where(['OR' => $clauses]);
            return;
        }
        if ($due === 'upcoming') {
            $start = $today->modify('+8 days');
            $clauses = [['dateEnd>=' => $start->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s')] + ($dateOnly ? ['dateEndDate' => null] : [])];
            if ($dateOnly) $clauses[] = ['dateEndDate>=' => $start->format('Y-m-d')];
            $query->where(['OR' => $clauses]);
            return;
        }
        // Keep the legacy tomorrow key for the seven calendar days after today.
        $start = $today->modify('+1 day');
        $end = $today->modify('+8 days');
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
        $groupByRead = ($filters['group'] ?? '') === 'read';
        $union = UnionBuilder::create()->all();
        $types = $this->types($filters);
        $total = 0;
        foreach ($types as $type) {
            $query = $this->query($type, $tenant, $filters);
            $repo = $this->em->getRDBRepository($type);
            $total += $repo->clone($query->build())->count();
            $expression = $sort === 'dateEnd' && $type !== 'Call' ? 'COALESCE:(dateEndDate, dateEnd)' : $sort;
            $columns = ['id', ['VALUE:' . $type, 'type'], [$expression, 'inboxSort']];
            if ($groupByRead) {
                // Group before pagination so unread records cannot be hidden on a later page.
                $unread = $this->query($type, $tenant, ['read_status' => 'unread'])->select('id')->distinct()->build();
                $query->leftJoin($unread, 'inboxUnread', Expr::equal(Expr::alias('inboxUnread.id'), Expr::column('id')));
                $columns[] = [Expr::if(Expr::isNull(Expr::alias('inboxUnread.id')), 0, 1), 'inboxGroup'];
            }
            // Sort the union in the database: PHP string ordering disagrees with
            // database collations for names, invalidating merged prefix pagination.
            $union->query($query->select($columns)->order([])->build());
        }
        $ordering = $groupByRead ? [['inboxGroup', 'DESC']] : [];
        $page = $types ? $this->em->getQueryExecutor()->execute(
            $union->order([...$ordering, ['inboxSort', $order], ['type', 'ASC'], ['id', 'ASC']])->limit($offset, $limit)->build()
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
            $tagged = $this->tags->decorate($type, array_map(fn ($item) => $this->present($item), [...$items]));
            foreach ($tagged as $row) {
                $row->readState = $states[$row->id] ?? null;
                $row->lastPost = $previews[$row->id] ?? null;
                $records[$type . ':' . $row->id] = $row;
            }
        }
        return (object) ['list' => array_values(array_filter(array_map(fn ($row) => $records[$row['type'] . ':' . $row['id']] ?? null, $page))), 'total' => $total, 'hasMore' => $offset + $limit < $total];
    }

    public function counts(Entity $tenant, array $filters): object
    {
        // Counts describe sidebar destinations, retaining only the global assignee scope.
        $base = array_intersect_key($filters, array_flip(['assignee_tab', 'timeZone']));
        $result = ['all' => 0, 'unread' => 0, 'mentions' => 0, 'types' => [], 'status' => [], 'statusGroups' => ['Open' => 0, 'Finished' => 0], 'users' => [], 'due' => [], 'tags' => []];
        foreach ($this->types([]) as $type) {
            $repo = $this->em->getRDBRepository($type);
            if (($filters['railOnly'] ?? '') === 'true') {
                $result['unread'] += $repo->clone($this->query($type, $tenant, ['read_status' => 'unread'])->build())->count();
                continue;
            }
            $query = $this->query($type, $tenant, $base);
            foreach ($this->tags->counts($type, $query) as $id => $count) {
                $result['tags'][$id] = ($result['tags'][$id] ?? 0) + $count;
            }
            $count = $repo->clone($query->build())->count();
            $result['all'] += $count;
            $result['types'][$type] = $count;
            $finishedStatuses = $this->finishedStatuses($type);
            foreach (['status' => 'status', 'users' => 'assignedUserId'] as $key => $field) {
                $groupQuery = $key === 'users' ? $this->query($type, $tenant, []) : clone $query;
                $grouped = $groupQuery->select([$field, ['COUNT:id', 'count']])->group($field)->build();
                foreach ($this->em->getQueryExecutor()->execute($grouped)->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                    if ($row[$field]) $result[$key][$row[$field]] = ($result[$key][$row[$field]] ?? 0) + (int) $row['count'];
                    if ($key === 'status') {
                        $status = in_array($row[$field], $finishedStatuses, true) ? 'Finished' : 'Open';
                        $result['statusGroups'][$status] += (int) $row['count'];
                    }
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
        $fields = ['name', 'status', 'priority', 'direction', 'description', 'dateStart', 'dateEnd', 'isAllDay', 'parent', 'assignedUser', 'teams', 'users', 'contacts', 'leads', 'tags', 'reminders', 'duration'];
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
                'finishedStatuses' => $this->finishedStatuses($type),
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

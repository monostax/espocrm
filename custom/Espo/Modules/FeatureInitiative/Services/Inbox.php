<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureInitiative\Services;

use DateTimeImmutable;
use DateTimeZone;
use Espo\Core\Acl;
use Espo\Core\Api\Request;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Record\SearchParamsFetcher;
use Espo\Core\Record\ServiceContainer;
use Espo\Core\Select\SearchParams;
use Espo\Core\Select\SelectBuilderFactory;
use Espo\Core\Utils\Metadata;
use Espo\Entities\User;
use Espo\Modules\Chatwoot\Services\ActivityDiscussion;
use Espo\Modules\Chatwoot\Services\OpportunityBulkPostAccess;
use Espo\Modules\Chatwoot\Tools\Activities\Access as ActivityAccess;
use Espo\Modules\Chatwoot\Tools\Stream\OpportunityAccess;
use Espo\Modules\Global\Services\RecordActivityBuckets;
use Espo\Modules\Global\Tools\CrmTags;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Query\Part\Expression as Expr;
use Espo\ORM\Query\Select;
use Espo\ORM\Query\SelectBuilder;
use PDO;

/** Workspace-scoped inbox queries use the live initiative ACL before pagination or aggregation. */
class Inbox
{
    private const GROUP_FIELDS = [
        'initiativeType' => ['initiativeTypeId', 'initiativeType'],
        'stage' => ['stageId', 'stage'],
        'status' => ['status', 'status'],
        'assignee' => ['assignedUserId', 'assignedUser'],
    ];

    public function __construct(
        private EntityManager $em,
        private Acl $acl,
        private User $user,
        private SelectBuilderFactory $select,
        private SearchParamsFetcher $searchParams,
        private ServiceContainer $records,
        private OpportunityBulkPostAccess $workspaces,
        private ActivityDiscussion $discussion,
        private RecordActivityBuckets $buckets,
        private Metadata $metadata,
        private OpportunityAccess $streamAccess,
        private ActivityAccess $activityAccess,
        private CrmTags $tags,
    ) {}

    public function workspace(Request $request): Entity
    {
        $id = filter_var($request->getQueryParam('accountId'), FILTER_VALIDATE_INT);
        if (!$id || $id < 1) {
            throw new BadRequest('A workspace is required.');
        }
        return $this->workspaces->workspace($id);
    }

    public function record(Request $request, bool $stream = false, ?string $id = null): Entity
    {
        $workspace = $this->workspace($request);
        $record = $this->em->getEntityById('Initiative', $id ?? (string) $request->getRouteParam('id'));
        if (!$record || $record->get('tenantId') !== $workspace->get('tenantId') || !$this->acl->checkEntityRead($record)) {
            throw new NotFound();
        }
        if ($stream && (!$this->acl->checkEntity($record, 'stream') || !$this->acl->checkScope('Note', 'read'))) {
            throw new Forbidden();
        }
        return $record;
    }

    private function readableStreamScope(): Select
    {
        return $this->streamAccess->readableInitiatives($this->user) ??
            SelectBuilder::create()->from('Initiative')->select('id')->where(['id' => null])->build();
    }

    public function scope(Request $request): Select
    {
        $workspace = $this->workspace($request);
        if (!$this->acl->checkScope('Initiative', 'read')) {
            throw new Forbidden();
        }
        $params = $this->searchParams->fetch($request)
            ->withSelect(['id'])->withOrderBy(null)->withOffset(null)->withMaxSize(null);
        $query = $this->select->create()->from('Initiative')->withSearchParams($params)
            ->withStrictAccessControl()->withWherePermissionCheck()->withComplexExpressionsForbidden()->buildQueryBuilder()
            ->where(['tenantId' => $workspace->get('tenantId')])->select(['id'])->distinct()->order([])->limit(null, null);
        $assignee = $request->getQueryParam('assignee_tab');
        if ($assignee === 'me') $query->where(['assignedUserId' => $this->user->getId()]);
        elseif ($assignee === 'unassigned') $query->where(['assignedUserId' => null]);
        if ($tagId = $request->getQueryParam('tag')) {
            if (!$this->acl->checkField('Initiative', 'tags') || !$this->acl->checkScope('CrmTag', 'read')) {
                $query->where(['id' => null]);
            } else {
                $tag = $this->tags->query()->where(['id' => $tagId, 'tenantId' => $workspace->get('tenantId')])->select(['id'])->build();
                $tagged = SelectBuilder::create()->from('Initiative')->select(['id'])->join('tags', 'crmTag')->where(['crmTag.id=s' => $tag])->build();
                $query->where(['id=s' => $tagged]);
            }
        }
        $read = $request->getQueryParam('read_status');
        if ($read === 'unread' || $request->getQueryParam('view') === 'mentions') {
            if (!$this->acl->checkScope('Note', 'read') || !$this->acl->checkScope('Initiative', 'stream')) {
                $query->where(['id' => null]);
            } else {
                $query->where(['id=s' => $this->readableStreamScope()]);
                $this->discussion->applyFilter($query, 'Initiative', $read === 'unread');
            }
        }
        return $query->build();
    }

    public function groupBy(Request $request): string
    {
        $group = $request->getQueryParam('groupBy') ?: 'readStatus';
        if (!in_array($group, [...array_keys(self::GROUP_FIELDS), 'readStatus', 'activity', 'none'], true)) {
            throw new BadRequest('Invalid initiative grouping.');
        }
        if (isset(self::GROUP_FIELDS[$group]) && !$this->acl->checkField('Initiative', self::GROUP_FIELDS[$group][1])) {
            throw new Forbidden();
        }
        return $group;
    }

    public function groupedQuery(Select $scope, Request $request, string $group): array
    {
        // A semi-join avoids duplicate records from team and relationship filters.
        $query = SelectBuilder::create()->from('Initiative')->where(['id=s' => $scope]);
        $bucket = null;
        $activity = $request->getQueryParam('activity');
        if ($group === 'activity' || $activity) {
            if ($activity && !in_array($activity, RecordActivityBuckets::KEYS, true)) {
                throw new BadRequest('Invalid activity bucket.');
            }
            try { $zone = new DateTimeZone($request->getQueryParam('timeZone') ?: 'UTC'); }
            catch (\Exception) { throw new BadRequest('Invalid time zone.'); }
            $bucket = $this->buckets->apply($query, $scope, new DateTimeImmutable('now', $zone), 'Initiative');
            if ($activity) $query->where(Expr::in($bucket, $activity === 'today' ? ['overdue', 'today'] : [$activity]));
        }
        if ($group === 'readStatus') {
            $unread = SelectBuilder::create()->clone($scope);
            if ($this->acl->checkScope('Note', 'read') && $this->acl->checkScope('Initiative', 'stream')) {
                $unread->where(['id=s' => $this->readableStreamScope()]);
                $this->discussion->applyFilter($unread, 'Initiative', true);
            } else {
                $unread->where(['id' => null]);
            }
            $query->leftJoin($unread->distinct()->build(), 'inboxUnread', Expr::equal(Expr::alias('inboxUnread.id'), Expr::column('id')));
            $key = Expr::if(Expr::isNull(Expr::alias('inboxUnread.id')), 'read', 'unread');
        } else {
            $key = match ($group) {
                'none' => Expr::value('all'),
                'activity' => Expr::concat('activity:', $bucket),
                default => Expr::concat($group . ':', Expr::ifNull(Expr::column(self::GROUP_FIELDS[$group][0]), '')),
            };
        }
        return [$query, $key];
    }

    public function groups(Request $request): object
    {
        $group = $this->groupBy($request);
        [$query, $key] = $this->groupedQuery($this->scope($request), $request, $group);
        $rows = $this->em->getQueryExecutor()->execute(
            $query->select($key, 'groupKey')->select(Expr::count(Expr::column('id')), 'count')->group(Expr::alias('groupKey'))->build(),
        )->fetchAll(PDO::FETCH_ASSOC);
        $groups = [];
        foreach ($rows as $row) $groups[$row['groupKey']] = ['count' => (int) $row['count']];
        return (object) ['groups' => (object) $groups];
    }

    public function list(Request $request): object
    {
        $scope = $this->scope($request);
        $group = $this->groupBy($request);
        [$query, $key] = $this->groupedQuery($scope, $request, $group);
        $groupKey = $request->getQueryParam('groupKey');
        if ($groupKey !== null) $query->where(Expr::equal($key, $groupKey));
        $totalQuery = (clone $query)->select(Expr::count(Expr::column('id')), 'count')->build();
        $total = (int) $this->em->getQueryExecutor()->execute($totalQuery)->fetchColumn();
        $offset = max(0, (int) $request->getQueryParam('offset'));
        $limit = min(100, max(1, (int) ($request->getQueryParam('maxSize') ?: 25)));
        $sort = $request->getQueryParam('sort') ?: 'createdAt';
        if (!in_array($sort, ['createdAt', 'modifiedAt', 'name', 'initiativeTypeName', 'stageName', 'status', 'assignedUserName'], true)) {
            throw new BadRequest('Invalid initiative sort.');
        }
        $sortField = ['initiativeTypeName' => 'initiativeType', 'stageName' => 'stage', 'assignedUserName' => 'assignedUser'][$sort] ?? $sort;
        if (!$this->acl->checkField('Initiative', $sortField)) throw new Forbidden();
        $order = $request->getQueryParam('order') === 'asc' ? 'ASC' : 'DESC';
        $query->select(['id'])->select($key, 'groupKey');
        // Unread records and server-defined groups are ranked before pagination.
        if ($group !== 'none') $query->order(Expr::alias('groupKey'), $group === 'readStatus' ? 'DESC' : 'ASC');
        $page = $this->em->getQueryExecutor()->execute(
            $query->order($sort, $order)->order('id', 'ASC')->limit($offset, $limit)->build(),
        )->fetchAll(PDO::FETCH_ASSOC);
        $ids = array_column($page, 'id');
        if (!$ids) return (object) ['list' => [], 'total' => $total, 'hasMore' => false];
        $items = $this->records->get('Initiative')->find(SearchParams::fromRaw([
            'maxSize' => $limit, 'where' => [['type' => 'in', 'attribute' => 'id', 'value' => $ids]],
        ]))->getCollection();
        $streamIds = [];
        $records = [];
        foreach ($items as $item) {
            $data = $this->present($item);
            $records[$item->getId()] = $data;
            if ($data->canStream) $streamIds[] = $item->getId();
        }
        $states = $this->discussion->states('Initiative', $streamIds);
        $list = [];
        foreach ($page as $row) {
            if (!isset($records[$row['id']])) continue;
            $data = $records[$row['id']];
            $data->groupKey = $row['groupKey'];
            $data->readState = $states[$row['id']] ?? null;
            $list[] = $data;
        }
        return (object) ['list' => $this->tags->decorate('Initiative', $list), 'total' => $total, 'hasMore' => $offset + count($page) < $total];
    }

    public function counts(Request $request): object
    {
        $scope = $this->scope($request);
        $result = ['all' => 0, 'unread' => 0, 'status' => [], 'stages' => [], 'types' => [], 'users' => []];
        $result['all'] = $this->em->getRDBRepository('Initiative')->clone($scope)->count();
        if ($this->acl->checkScope('Note', 'read') && $this->acl->checkScope('Initiative', 'stream')) {
            $unread = SelectBuilder::create()->clone($scope);
            $unread->where(['id=s' => $this->readableStreamScope()]);
            $this->discussion->applyFilter($unread, 'Initiative', true);
            $result['unread'] = $this->em->getRDBRepository('Initiative')->clone($unread->build())->count();
        }
        if ($request->getQueryParam('railOnly') === 'true') return (object) ['unread' => $result['unread']];
        $result['tags'] = $this->tags->counts('Initiative', SelectBuilder::create()->clone($scope));
        $result['tagsUnread'] = isset($unread) ? $this->tags->counts('Initiative', $unread) : [];
        foreach (['status' => 'status', 'stages' => 'stageId', 'types' => 'initiativeTypeId', 'users' => 'assignedUserId'] as $name => $field) {
            $link = ['stages' => 'stage', 'types' => 'initiativeType', 'users' => 'assignedUser'][$name] ?? $field;
            if (!$this->acl->checkField('Initiative', $link)) continue;
            $query = SelectBuilder::create()->from('Initiative')->where(['id=s' => $scope])
                ->select([$field, [Expr::count(Expr::column('id')), 'count']])->group($field)->build();
            foreach ($this->em->getQueryExecutor()->execute($query)->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $result[$name][$row[$field] ?? ''] = (int) $row['count'];
            }
        }
        [$query, $key] = $this->groupedQuery($scope, $request, 'activity');
        $result['activity'] = [];
        foreach ($this->em->getQueryExecutor()->execute(
            $query->select($key, 'groupKey')->select(Expr::count(Expr::column('id')), 'count')->group(Expr::alias('groupKey'))->build(),
        )->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result['activity'][substr($row['groupKey'], strlen('activity:'))] = (int) $row['count'];
        }
        return (object) $result;
    }

    public function present(Entity $entity): object
    {
        return (object) ((array) $entity->getValueMap() + [
            'entityType' => 'Initiative', 'canEdit' => $this->acl->checkEntityEdit($entity),
            'canDelete' => $this->acl->checkEntityDelete($entity),
            'canStream' => $this->acl->checkEntity($entity, 'stream') && $this->acl->checkScope('Note', 'read'),
            'canPost' => $this->acl->checkEntity($entity, 'stream') && $this->acl->checkScope('Note', 'create') && $this->acl->checkScope('Note', 'read'),
        ]);
    }

    public function metadata(Request $request): object
    {
        $this->workspace($request);
        if (!$this->acl->checkScope('Initiative', 'read')) throw new Forbidden();
        $fields = [];
        foreach (['name', 'initiativeType', 'stage', 'status', 'assignedUser', 'tags', 'description', 'createdAt', 'modifiedAt', 'createdBy'] as $name) {
            if (!$this->acl->checkField('Initiative', $name)) continue;
            $def = $this->metadata->get(['entityDefs', 'Initiative', 'fields', $name]) ?? [];
            $fields[$name] = array_intersect_key($def, array_flip(['type', 'options', 'required', 'readOnly', 'readOnlyAfterCreate', 'maxLength']));
            if (!$this->acl->checkField('Initiative', $name, 'edit')) $fields[$name]['readOnly'] = true;
        }
        return (object) ['Initiative' => ['fields' => $fields, 'canCreate' => $this->acl->checkScope('Initiative', 'create')]];
    }

    public function options(Request $request): object
    {
        $workspace = $this->workspace($request);
        $entity = $request->getQueryParam('entity');
        if (!in_array($entity, ['InitiativeType', 'InitiativeStage', 'User'], true)) throw new BadRequest('Invalid initiative option.');
        $query = $this->select->create()->from($entity)->withStrictAccessControl()->buildQueryBuilder();
        if ($entity === 'User') {
            $tenant = $this->em->getEntityById('Tenant', (string) $workspace->get('tenantId'));
            if (!$tenant) throw new NotFound('Workspace tenant not found.');
            $query->join('teams', 'initiativeTeam')->where(['initiativeTeam.id' => $this->activityAccess->teamIds($tenant)])
                ->where(['isActive' => true, 'type' => ['regular', 'admin', 'super-admin']])->distinct();
        } else {
            $query->where(['tenantId' => $workspace->get('tenantId')]);
            if ($request->getQueryParam('active') === 'true') $query->where(['isActive' => true]);
        }
        if ($entity === 'InitiativeStage' && $request->getQueryParam('initiativeTypeId')) {
            $query->where(['initiativeTypeId' => $request->getQueryParam('initiativeTypeId')]);
        }
        $search = trim($request->getQueryParam('search') ?: '');
        if ($search !== '') $query->where(['name*' => '%' . $search . '%']);
        $offset = max(0, (int) $request->getQueryParam('offset'));
        $query->select('id')->order($entity === 'InitiativeStage' ? 'order' : 'name')->limit($offset, 100);
        $ids = array_map(fn ($record) => $record->getId(), [...$this->em->getRDBRepository($entity)->clone($query->build())->find()]);
        if (!$ids) return (object) ['list' => [], 'hasMore' => false];
        $list = $this->records->get($entity)->find(SearchParams::fromRaw([
            'maxSize' => 100, 'orderBy' => $entity === 'InitiativeStage' ? 'order' : 'name', 'order' => 'asc',
            'select' => $entity === 'User' ? ['id', 'name'] : ($entity === 'InitiativeStage'
                ? ['id', 'name', 'initiativeTypeId', 'initiativeTypeName', 'order', 'category', 'isActive'] : ['id', 'name', 'isActive']),
            'where' => [['type' => 'in', 'attribute' => 'id', 'value' => $ids]],
        ]))->getValueMapList();
        return (object) ['list' => $list, 'hasMore' => count($ids) === 100];
    }

    public function create(Request $request): object
    {
        $workspace = $this->workspace($request);
        $data = clone $request->getParsedBody();
        $type = isset($data->initiativeTypeId) && is_string($data->initiativeTypeId)
            ? $this->em->getEntityById('InitiativeType', $data->initiativeTypeId) : null;
        if (!$type || $type->get('tenantId') !== $workspace->get('tenantId')) throw new BadRequest('Choose an initiative type from this workspace.');
        return $this->present($this->records->get('Initiative')->create($data)->getEntity());
    }

    public function update(Request $request): object
    {
        $record = $this->record($request);
        return $this->present($this->records->get('Initiative')->update($record->getId(), $request->getParsedBody())->getEntity());
    }

    public function delete(Request $request): bool
    {
        $record = $this->record($request);
        $this->records->get('Initiative')->delete($record->getId());
        return true;
    }
}

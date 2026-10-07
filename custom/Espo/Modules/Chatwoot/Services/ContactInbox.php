<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Services;

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
use Espo\Modules\Chatwoot\Tools\Activities\Access;
use Espo\Modules\Chatwoot\Tools\Stream\OpportunityAccess;
use Espo\Modules\Global\Tools\CrmTags;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Query\Part\Expression as Expr;
use Espo\ORM\Query\Select;
use Espo\ORM\Query\SelectBuilder;
use PDO;

/** Contact's own inbox: all grouping, ordering and counts precede pagination. */
class ContactInbox
{
    protected const ENTITY_TYPE = 'Contact';
    protected const SORT_FIELDS = ['streamUpdatedAt', 'createdAt', 'modifiedAt', 'name', 'accountName', 'assignedUserName', 'source'];
    protected const EDIT_FIELDS = ['firstName', 'lastName', 'accountId', 'assignedUserId', 'tags', 'crmTagsIds', 'source', 'emailAddress', 'phoneNumber', 'description', 'customFields'];
    protected const GROUP_FIELDS = [
        'assignee' => ['assignedUserId', 'assignedUser'],
        'account' => ['accountId', 'account'],
        'source' => ['source', 'source'],
    ];
    protected const FIELDS = [
        'name', 'firstName', 'lastName', 'account', 'assignedUser', 'teams', 'tags', 'crmTags', 'source',
        'emailAddress', 'phoneNumber', 'channelIdentitiesData', 'description', 'customFields',
        'createdAt', 'modifiedAt', 'createdBy',
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
        private OpportunityAccess $streamAccess,
        private Access $activityAccess,
        private Metadata $metadata,
        private CrmTags $tags,
    ) {}

    public function workspace(Request $request): Entity
    {
        $id = filter_var($request->getQueryParam('accountId'), FILTER_VALIDATE_INT);
        if (!$id || $id < 1) throw new BadRequest('A workspace is required.');
        if (!$this->acl->checkScope(static::ENTITY_TYPE, 'read')) throw new Forbidden();
        return $this->workspaces->workspace($id);
    }

    public function record(Request $request, bool $stream = false, ?string $id = null): Entity
    {
        $workspace = $this->workspace($request);
        $record = $this->em->getEntityById(static::ENTITY_TYPE, $id ?? (string) $request->getRouteParam('id'));
        if (!$record || $record->get('tenantId') !== $workspace->get('tenantId') || !$this->acl->checkEntityRead($record)) {
            throw new NotFound();
        }
        if ($stream && (!$this->acl->checkEntityStream($record) || !$this->acl->checkScope('Note', 'read'))) throw new Forbidden();
        return $record;
    }

    private function unreadScope(Select $scope, bool $unread = true): Select
    {
        $query = SelectBuilder::create()->clone($scope);
        $readable = $this->streamAccess->readableParents($this->user, static::ENTITY_TYPE);
        if (!$readable || !$this->acl->checkScope('Note', 'read')) return $query->where(['id' => null])->build();
        $query->where(['id=s' => $readable]);
        $this->discussion->applyFilter($query, static::ENTITY_TYPE, $unread);
        return $query->build();
    }

    private function scope(Request $request): Select
    {
        $workspace = $this->workspace($request);
        $params = $this->searchParams->fetch($request)->withSelect(['id'])->withOrderBy(null)->withOffset(null)->withMaxSize(null);
        $query = $this->select->create()->from(static::ENTITY_TYPE)->withSearchParams($params)
            ->withStrictAccessControl()->withWherePermissionCheck()->withComplexExpressionsForbidden()->buildQueryBuilder()
            ->where(['tenantId' => $workspace->get('tenantId')])->select(['id'])->distinct()->order([])->limit(null, null);
        $assignee = $request->getQueryParam('assignee_tab');
        if (in_array($assignee, ['me', 'unassigned'], true)) {
            if (!$this->acl->checkField(static::ENTITY_TYPE, 'assignedUser')) throw new Forbidden();
            $query->where(['assignedUserId' => $assignee === 'me' ? $this->user->getId() : null]);
        }
        $scope = $query->build();
        if ($tag = $request->getQueryParam('tag')) {
            if (!$this->acl->checkField(static::ENTITY_TYPE, 'crmTags') || !$this->acl->checkScope('CrmTag', 'read')) throw new Forbidden();
            $tagged = SelectBuilder::create()->from(static::ENTITY_TYPE)->select(['id'])->join('crmTags', 'crmTag')
                ->where(['crmTag.id=s' => $this->tags->query()->select(['id'])->where(['id' => $tag])->build()])->build();
            $query->where(['id=s' => $tagged]);
        }
        if ($request->getQueryParam('view') === 'mentions') $query->where(['id=s' => $this->unreadScope($scope, false)]);
        $read = $request->getQueryParam('read_status');
        if (in_array($read, ['read', 'unread'], true)) {
            $query->where([$read === 'unread' ? 'id=s' : 'id!=s' => $this->unreadScope($scope)]);
        }
        return $query->build();
    }

    private function groupBy(Request $request): string
    {
        $group = $request->getQueryParam('groupBy') ?: 'readStatus';
        if (!in_array($group, [...array_keys(static::GROUP_FIELDS), 'readStatus', 'none'], true)) throw new BadRequest('Invalid inbox grouping.');
        if (isset(static::GROUP_FIELDS[$group]) && !$this->acl->checkField(static::ENTITY_TYPE, static::GROUP_FIELDS[$group][1])) throw new Forbidden();
        return $group;
    }

    private function groupedQuery(Select $scope, string $group): array
    {
        $query = SelectBuilder::create()->from(static::ENTITY_TYPE)->where(['id=s' => $scope]);
        if ($group === 'readStatus') {
            $query->leftJoin($this->unreadScope($scope), 'contactUnread', Expr::equal(Expr::alias('contactUnread.id'), Expr::column('id')));
            $key = Expr::if(Expr::isNull(Expr::alias('contactUnread.id')), 'read', 'unread');
        } else {
            $key = $group === 'none' ? Expr::value('all')
                : Expr::concat($group . ':', Expr::ifNull(Expr::column(static::GROUP_FIELDS[$group][0]), ''));
        }
        return [$query, $key];
    }

    public function groups(Request $request): object
    {
        [$query, $key] = $this->groupedQuery($this->scope($request), $this->groupBy($request));
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
        [$query, $key] = $this->groupedQuery($scope, $group);
        if (($groupKey = $request->getQueryParam('groupKey')) !== null) $query->where(Expr::equal($key, $groupKey));
        $total = (int) $this->em->getQueryExecutor()->execute((clone $query)->select(Expr::count(Expr::column('id')), 'count')->build())->fetchColumn();
        $offset = max(0, (int) $request->getQueryParam('offset'));
        $limit = min(100, max(1, (int) ($request->getQueryParam('maxSize') ?: 25)));
        $sort = $request->getQueryParam('sort') ?: 'streamUpdatedAt';
        if (!in_array($sort, static::SORT_FIELDS, true)) {
            throw new BadRequest('Invalid inbox sort.');
        }
        if ($sort === 'streamUpdatedAt') {
            // Only visible mural posts contribute to the inbox's last-update order.
            $readable = $this->streamAccess->readableParents($this->user, static::ENTITY_TYPE);
            $posts = SelectBuilder::create()->from('Note')->select(['parentId', ['MAX:createdAt', 'updatedAt']])
                ->where(['parentType' => static::ENTITY_TYPE, 'type' => 'Post', 'opportunityPostDeleted' => false])->group('parentId');
            if ($readable && $this->acl->checkScope('Note', 'read')) $posts->where(['parentId=s' => $readable]);
            else $posts->where(['id' => null]);
            $query->leftJoin($posts->build(), 'contactMural', Expr::equal(Expr::alias('contactMural.parentId'), Expr::column('id')));
            $sortExpression = Expr::ifNull(Expr::alias('contactMural.updatedAt'), Expr::column('createdAt'));
        } else {
            $field = ['accountName' => 'account', 'assignedUserName' => 'assignedUser'][$sort] ?? $sort;
            if (!$this->acl->checkField(static::ENTITY_TYPE, $field)) throw new Forbidden();
            $sortExpression = $sort;
        }
        $query->select(['id'])->select($key, 'groupKey');
        if ($group !== 'none') $query->order(Expr::alias('groupKey'), $group === 'readStatus' ? 'DESC' : 'ASC');
        $page = $this->em->getQueryExecutor()->execute(
            $query->order($sortExpression, $request->getQueryParam('order') === 'asc' ? 'ASC' : 'DESC')->order('id', 'ASC')->limit($offset, $limit)->build(),
        )->fetchAll(PDO::FETCH_ASSOC);
        $ids = array_column($page, 'id');
        if (!$ids) return (object) ['list' => [], 'total' => $total, 'hasMore' => false];
        $items = $this->records->get(static::ENTITY_TYPE)->find(SearchParams::fromRaw([
            'maxSize' => $limit, 'where' => [['type' => 'in', 'attribute' => 'id', 'value' => $ids]],
        ]))->getCollection();
        $records = [];
        $streamIds = [];
        foreach ($items as $item) {
            $records[$item->getId()] = $this->present($item, false);
            if ($records[$item->getId()]->canStream) $streamIds[] = $item->getId();
        }
        $states = $this->discussion->states(static::ENTITY_TYPE, $streamIds);
        $list = [];
        foreach ($page as $row) {
            if (!isset($records[$row['id']])) continue;
            $data = $records[$row['id']];
            $data->groupKey = $row['groupKey'];
            $data->readState = $states[$row['id']] ?? null;
            $list[] = $data;
        }
        return (object) ['list' => $this->tags->decorate(static::ENTITY_TYPE, $list), 'total' => $total, 'hasMore' => $offset + count($page) < $total];
    }

    public function counts(Request $request): object
    {
        $scope = $this->scope($request);
        return (object) [
            'all' => $this->em->getRDBRepository(static::ENTITY_TYPE)->clone($scope)->count(),
            'unread' => $this->em->getRDBRepository(static::ENTITY_TYPE)->clone($this->unreadScope($scope))->count(),
            'mentions' => $this->em->getRDBRepository(static::ENTITY_TYPE)->clone($this->unreadScope($scope, false))->count(),
            'tags' => (object) $this->tags->counts(static::ENTITY_TYPE, SelectBuilder::create()->clone($scope)),
        ];
    }

    public function present(Entity $entity, bool $withTags = true): object
    {
        $data = (object) ((array) $entity->getValueMap() + [
            'entityType' => static::ENTITY_TYPE, 'canEdit' => $this->acl->checkEntityEdit($entity), 'canDelete' => $this->acl->checkEntityDelete($entity),
            'canStream' => $this->acl->checkEntityStream($entity) && $this->acl->checkScope('Note', 'read'),
            'canPost' => $this->acl->checkEntityStream($entity) && $this->acl->checkScope('Note', 'create') && $this->acl->checkScope('Note', 'read'),
        ]);
        return $withTags ? $this->tags->decorate(static::ENTITY_TYPE, [$data])[0] : $data;
    }

    public function metadata(Request $request): object
    {
        $this->workspace($request);
        $fields = [];
        foreach (static::FIELDS as $name) {
            if (!$this->acl->checkField(static::ENTITY_TYPE, $name)) continue;
            $def = $this->metadata->get(['entityDefs', static::ENTITY_TYPE, 'fields', $name]);
            if (!$def) continue;
            $fields[$name] = array_intersect_key($def, array_flip(['type', 'options', 'required', 'readOnly', 'maxLength']));
            if (!$this->acl->checkField(static::ENTITY_TYPE, $name, 'edit')) $fields[$name]['readOnly'] = true;
        }
        return (object) [static::ENTITY_TYPE => ['fields' => $fields, 'canCreate' => $this->acl->checkScope(static::ENTITY_TYPE, 'create')]];
    }

    public function options(Request $request): object
    {
        $workspace = $this->workspace($request);
        $entity = $request->getQueryParam('entity');
        if (!in_array($entity, ['Account', 'User', 'Team'], true)) throw new BadRequest('Invalid contact option.');
        if (!$this->acl->checkScope($entity, 'read')) return (object) ['list' => [], 'hasMore' => false];
        $query = $this->select->create()->from($entity)->withStrictAccessControl()->buildQueryBuilder();
        if ($entity === 'Account') $query->where(['tenantId' => $workspace->get('tenantId')]);
        else {
            $tenant = $this->em->getEntityById('Tenant', (string) $workspace->get('tenantId'));
            if (!$tenant) throw new NotFound();
            $teams = $this->activityAccess->teamIds($tenant);
            if ($entity === 'Team') $query->where(['id' => $teams]);
            else $query->join('teams', 'contactTeam')->where(['contactTeam.id' => $teams, 'isActive' => true, 'type' => ['regular', 'admin', 'super-admin']])->distinct();
        }
        if ($search = trim($request->getQueryParam('search') ?: '')) $query->where(['name*' => '%' . $search . '%']);
        $offset = max(0, (int) $request->getQueryParam('offset'));
        $query->select('id')->order('name')->limit($offset, 100);
        $ids = array_map(fn ($record) => $record->getId(), [...$this->em->getRDBRepository($entity)->clone($query->build())->find()]);
        if (!$ids) return (object) ['list' => [], 'hasMore' => false];
        $list = $this->records->get($entity)->find(SearchParams::fromRaw([
            'maxSize' => 100, 'orderBy' => 'name', 'order' => 'asc', 'select' => ['id', 'name'],
            'where' => [['type' => 'in', 'attribute' => 'id', 'value' => $ids]],
        ]))->getValueMapList();
        return (object) ['list' => $list, 'hasMore' => count($ids) === 100];
    }

    public function update(Request $request): object
    {
        $record = $this->record($request);
        $data = $request->getParsedBody();
        // The inbox edits a record inside its current workspace, never moves it.
        if (array_diff(array_keys((array) $data), static::EDIT_FIELDS)) throw new BadRequest('Unsupported inbox fields.');
        if (!empty($data->accountId)) {
            $account = $this->em->getEntityById('Account', $data->accountId);
            if (!$account || $account->get('tenantId') !== $record->get('tenantId') || !$this->acl->checkEntityRead($account)) throw new Forbidden();
        }
        return $this->present($this->records->get(static::ENTITY_TYPE)->update($record->getId(), $data)->getEntity());
    }

    public function delete(Request $request): bool
    {
        $record = $this->record($request);
        $this->records->get(static::ENTITY_TYPE)->delete($record->getId());
        return true;
    }
}

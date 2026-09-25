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
use Espo\Entities\User;
use Espo\Modules\Chatwoot\Services\OpportunityActivityBuckets;
use Espo\Modules\Chatwoot\Services\OpportunityReadStateService;
use Espo\Modules\Chatwoot\Services\OpportunityStreamEvents;
use Espo\Modules\Chatwoot\Services\OpportunityThreadState;
use Espo\Modules\Chatwoot\Tools\Stream\OpportunityEventAccess;
use Espo\Modules\Global\Tools\Tenant\UserTenantResolver;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Query\Part\Expression as Expr;

class Feed
{
    private const PAGE_SIZE = 20;
    private const MESSAGE_LIMIT = 30;

    public function __construct(
        private EntityManager $em,
        private SelectBuilderFactory $select,
        private Acl $acl,
        private User $user,
        private UserTenantResolver $tenants,
        private OpportunityReadStateService $states,
        private OpportunityThreadState $threads,
        private OpportunityEventAccess $events,
        private OpportunityActivityBuckets $buckets,
        private DataCache $cache,
    ) {}

    public function list(Request $request): array
    {
        $this->authorize();
        $accountId = (string) $request->getQueryParam('accountId');
        if (!ctype_digit($accountId) || (int) $accountId < 1) throw new BadRequest('Workspace is required.');
        $accounts = $this->select->create()->from('ChatwootAccount')->withStrictAccessControl()->buildQueryBuilder()
            ->where(['chatwootAccountId' => (int) $accountId])->limit(0, 2)->build();
        $accounts = iterator_to_array($this->em->getRDBRepository('ChatwootAccount')->clone($accounts)->find());
        if (count($accounts) !== 1) throw new Forbidden('Workspace is unavailable.');
        $tenantId = reset($accounts)->get('tenantId');
        if (!$tenantId || (!$this->user->isAdmin() && !in_array($tenantId, $this->tenants->resolveTenantIds($this->user), true))) {
            throw new Forbidden();
        }
        $query = $this->select->create()->from('Opportunity')->withStrictAccessControl()->buildQueryBuilder()
            ->where(['tenantId' => $tenantId]);
        if ($request->getQueryParam('view') === 'attention') {
            $query->where(['assignedUserId' => $this->user->getId(), 'status!=' => ['Won', 'Lost']]);
            try {
                $now = new DateTimeImmutable('now', new DateTimeZone($request->getQueryParam('timeZone') ?: 'UTC'));
            } catch (\Exception) {
                throw new BadRequest('Invalid time zone.');
            }
            $scope = (clone $query)->select('id')->order([])->build();
            $bucket = $this->buckets->apply($query, $scope, $now);
            $query->where(Expr::in($bucket, ['overdue', 'noNextAction']))->select($bucket, 'catchUpReason');
        } else {
            $this->states->applyListFilter($query, true);
        }
        $cursor = $request->getQueryParam('cursor');
        if ($cursor) {
            if (!preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $cursor)) throw new BadRequest('Invalid cursor.');
            $query->where(['id<' => $cursor]);
        }
        $query->select(['id', 'name'])->order('id', 'DESC')->limit(0, self::PAGE_SIZE + 1);
        $records = array_values(iterator_to_array($this->em->getRDBRepository('Opportunity')->clone($query->build())->find()));
        $more = count($records) > self::PAGE_SIZE;
        $records = array_slice($records, 0, self::PAGE_SIZE);
        $states = $this->states->getReadStates(array_map(fn ($record) => $record->getId(), $records));
        return [
            'items' => array_map(fn ($record) => [
                'id' => $record->getId(), 'type' => 'opportunity', 'name' => $record->get('name'),
                'unreadCount' => $states[$record->getId()]['unreadCount'],
                'reason' => $record->get('catchUpReason'),
            ], $records),
            'cursor' => $more ? end($records)->getId() : null,
        ];
    }

    public function snapshot(string $id): array
    {
        $this->authorize();
        // Reuses record, stream and tenant authorization, including personal read versions.
        $state = $this->states->getReadState($id);
        $record = $this->em->getEntityById('Opportunity', $id);
        if (!$record) throw new NotFound();
        $base = $this->em->getRDBRepository('Note')->where([
            'parentType' => 'Opportunity', 'parentId' => $id, 'type' => OpportunityStreamEvents::TYPES,
        ])->where($this->events->where($this->user));
        $main = ['opportunityThreadRootId' => null];
        if ($state['lastSeenNumber'] !== null) $main['number>'] = $state['lastSeenNumber'];
        elseif ($state['lastSeenAt'] !== null) $main['createdAt>'] = $state['lastSeenAt'];
        else $main['id'] = '__none__';
        $notes = array_values(iterator_to_array((clone $base)->where(['OR' => [
            $main, OpportunityThreadState::unreadWhere($this->user->getId()),
        ]])->order('number')->limit(0, self::MESSAGE_LIMIT + 1)->find()));
        $more = count($notes) > self::MESSAGE_LIMIT;
        $notes = array_slice($notes, 0, self::MESSAGE_LIMIT);
        $contextIds = [];
        if ($notes) {
            $context = array_values(iterator_to_array((clone $base)->where(['number<' => $notes[0]->get('number'), 'opportunityThreadRootId' => null])
                ->order('number', 'DESC')->limit(0, 5)->find()));
            $contextIds = array_map(fn ($note) => $note->getId(), $context);
            $notes = [...array_reverse($context), ...$notes];
        } else {
            $notes = array_reverse(array_values(iterator_to_array((clone $base)->order('number', 'DESC')->limit(0, self::MESSAGE_LIMIT)->find())));
        }
        $lastPostId = null;
        $threadCutoffs = [];
        $evidence = [];
        foreach ($notes as $note) {
            if (!$this->acl->checkEntityRead($note)) continue;
            $rootId = $note->get('opportunityThreadRootId');
            if ($rootId) {
                $threadCutoffs[$rootId] = ['number' => (int) $note->get('number'), 'version' => $this->threads->readState($rootId)['version']];
            } else {
                $lastPostId = $note->getId();
            }
            $evidence[] = [
                'id' => $note->getId(), 'text' => mb_substr(strip_tags((string) $note->get('post')), 0, 4000),
                'kind' => $note->get('type'), 'at' => $note->get('createdAt'),
                'context' => in_array($note->getId(), $contextIds, true), 'threadRootId' => $rootId,
            ];
        }
        $facts = [];
        foreach (['name', 'status', 'stage', 'amount', 'amountCurrency', 'nextActionId', 'nextActionType', 'stageDueAt'] as $field) {
            if ($this->acl->checkField('Opportunity', $field)) $facts[$field] = $record->get($field);
        }
        $result = [
            'id' => $id, 'type' => 'opportunity', 'name' => $record->get('name'),
            'unreadCount' => $state['unreadCount'], 'evidence' => $evidence, 'facts' => $facts, 'hasMoreMessages' => $more,
        ];
        $review = ['lastPostId' => $lastPostId, 'version' => $state['version'], 'threads' => $threadCutoffs];
        $result['snapshot'] = hash('sha256', json_encode([$result, $review], JSON_THROW_ON_ERROR));
        $this->cache->store($this->snapshotKey($id), ['token' => $result['snapshot'], 'review' => $review, 'expires' => time() + 86400]);
        return $result;
    }

    public function review(string $id, string $token): void
    {
        $this->authorize();
        $state = $this->states->getReadState($id);
        $snapshot = $this->cache->tryGet($this->snapshotKey($id));
        if (!$snapshot || $snapshot['expires'] < time() || !hash_equals($snapshot['token'], $token) ||
            $state['version'] !== $snapshot['review']['version']) throw new Conflict('Refresh this card before reviewing it.');
        $review = $snapshot['review'];
        $this->em->getTransactionManager()->run(function () use ($id, $review): void {
            if ($review['lastPostId']) $this->states->markRead($id, $review['lastPostId'], $review['version']);
            foreach ($review['threads'] as $rootId => $cutoff) {
                $root = $this->em->getEntityById('Note', $rootId);
                if ($root && $root->get('parentId') === $id && $this->acl->checkEntityRead($root)) {
                    $this->threads->markRead($root, $cutoff['number'], $cutoff['version']);
                }
            }
        });
    }

    private function authorize(): void
    {
        if ((!$this->user->isRegular() && !$this->user->isAdmin()) ||
            !$this->acl->checkScope('Opportunity', 'read') || !$this->acl->checkScope('Opportunity', 'stream') ||
            !$this->acl->checkField('Opportunity', 'name')) throw new Forbidden();
    }

    private function snapshotKey(string $id): string
    {
        return 'catchUp/snapshots/' . hash('sha256', $this->user->getId() . ':' . $id);
    }
}

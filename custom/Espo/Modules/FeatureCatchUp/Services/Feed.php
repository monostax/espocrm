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
use Espo\Modules\Chatwoot\Services\OpportunityActivityBuckets;
use Espo\Modules\Chatwoot\Services\OpportunityReadStateService;
use Espo\Modules\Chatwoot\Services\OpportunityStreamEvents;
use Espo\Modules\Chatwoot\Services\OpportunityThreadState;
use Espo\Modules\Chatwoot\Tools\Stream\OpportunityEventAccess;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Query\Part\Expression as Expr;

class Feed
{
    private const PAGE_SIZE = 20;
    private const MESSAGE_LIMIT = 30;
    /** Shown on the card before its detail loads. */
    private const CARD_FIELDS = ['stage', 'amount', 'amountCurrency', 'contact', 'nextActionId', 'nextActionType', 'modifiedAt'];
    /** What the card copy is written from, beyond the stream evidence. */
    private const FACT_FIELDS = ['name', 'status', 'stage', 'amount', 'amountCurrency', 'closeDate', 'probability', 'stageDueAt', 'nextActionId', 'nextActionType'];

    public function __construct(
        private EntityManager $em,
        private SelectBuilderFactory $select,
        private Acl $acl,
        private User $user,
        private OpportunityReadStateService $states,
        private OpportunityThreadState $threads,
        private OpportunityEventAccess $events,
        private OpportunityActivityBuckets $buckets,
        private DataCache $cache,
        private Rollout $rollout,
        private Workspace $workspace,
        private Snoozes $snoozes,
        private Metadata $metadata,
    ) {}

    public function list(Request $request): array
    {
        $this->authorize();
        $tenantId = $this->workspace->resolve($request)['tenant']->getId();
        $query = $this->select->create()->from('Opportunity')->withStrictAccessControl()->buildQueryBuilder()
            ->where(['tenantId' => $tenantId]);
        $fields = array_values(array_filter(self::CARD_FIELDS, fn ($field) => $this->acl->checkField('Opportunity', $field)));
        // An array select replaces the selection, so it comes before the bucket column.
        $query->select(['id', 'name', ...array_map(fn ($field) => $field === 'contact' ? 'contactName' : $field, $fields)]);
        if ($request->getQueryParam('view') === 'attention') {
            $query->where(['assignedUserId' => $this->user->getId(), 'status!=' => ['Won', 'Lost']]);
            $scope = (clone $query)->select(['id'])->order([])->build();
            $bucket = $this->buckets->apply($query, $scope, Workspace::now($request));
            $query->where(Expr::in($bucket, ['overdue', 'noNextAction']))->select($bucket, 'catchUpReason');
        } else {
            $this->states->applyListFilter($query, true);
        }
        $query->where(['id!=s' => $this->snoozes->active('Opportunity')]);
        $cursor = $request->getQueryParam('cursor');
        if ($cursor) {
            if (!preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $cursor)) throw new BadRequest('Invalid cursor.');
            $query->where(['id<' => $cursor]);
        }
        $limit = Workspace::limit($request, self::PAGE_SIZE);
        $query->order('id', 'DESC')->limit(0, $limit + 1);
        $records = array_values(iterator_to_array($this->em->getRDBRepository('Opportunity')->clone($query->build())->find()));
        $more = count($records) > $limit;
        $records = array_slice($records, 0, $limit);
        $states = $this->states->getReadStates(array_map(fn ($record) => $record->getId(), $records));
        return [
            'items' => array_map(fn ($record) => [
                'id' => $record->getId(), 'type' => 'opportunity', 'name' => $record->get('name'),
                'unreadCount' => $states[$record->getId()]['unreadCount'] ?? 0,
                'reason' => $record->get('catchUpReason'),
                ...$this->cardFields($record, $fields),
            ], $records),
            'cursor' => $more ? end($records)->getId() : null,
        ];
    }

    public function snapshot(string $id, ?DateTimeImmutable $now = null): array
    {
        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->authorize();
        // Reuses record, stream and tenant authorization, including personal read versions.
        $state = $this->states->getReadState($id);
        $record = $this->em->getEntityById('Opportunity', $id);
        if (!$record) throw new NotFound();
        $policy = $this->rollout->forTenant((string) $record->get('tenantId'));
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
                'id' => $note->getId(), ...$this->sourceContent($note),
                'kind' => $note->get('type'), 'at' => $note->get('createdAt'),
                'context' => in_array($note->getId(), $contextIds, true), 'threadRootId' => $rootId,
            ];
        }
        $facts = [];
        foreach (self::FACT_FIELDS as $field) {
            if ($this->acl->checkField('Opportunity', $field)) $facts[$field] = $record->get($field);
        }
        if ($this->acl->checkField('Opportunity', 'contact')) $facts['contactName'] = $record->get('contactName');
        $nextAction = $this->nextAction($record);
        $reason = $this->reason($nextAction, $now);
        $customer = array_values(array_filter($evidence, fn ($entry) => $entry['kind'] === OpportunityStreamEvents::MESSAGE_RECEIVED));
        $lastCustomer = end($customer) ?: null;
        // Only day-stable values: a fact that changes every minute would supersede every summary poll.
        $facts += [
            'today' => $now->format('Y-m-d'), 'timeZone' => $now->getTimezone()->getName(), 'reason' => $reason,
            'nextActionName' => $nextAction['name'] ?? null, 'nextActionDue' => $nextAction ? $this->localDue($nextAction, $now) : null,
            'unreadCustomerMessages' => count(array_filter($customer, fn ($entry) => !$entry['context'])),
            'lastCustomerMessage' => $lastCustomer ? mb_substr($lastCustomer['text'], 0, 500) : null,
            'lastCustomerMessageAt' => $lastCustomer ? $this->local($lastCustomer['at'], $now) : null,
        ];
        $result = [
            'id' => $id, 'type' => 'opportunity', 'name' => $record->get('name'),
            'tenantId' => $record->get('tenantId'),
            'catchUpPolicy' => $policy,
            'unreadCount' => $state['unreadCount'], 'evidence' => $evidence, 'facts' => $facts, 'hasMoreMessages' => $more,
            'reason' => $reason, 'nextAction' => $nextAction,
            'lastConversation' => $lastCustomer && !empty($lastCustomer['conversationId'])
                ? ['conversationId' => $lastCustomer['conversationId'], 'accountId' => $lastCustomer['accountId'] ?? null] : null,
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
        $this->rollout->forOpportunity($id);
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

    private function cardFields(Entity $record, array $fields): array
    {
        $values = [];
        foreach ($fields as $field) {
            $values[$field === 'contact' ? 'contactName' : $field] = $record->get($field === 'contact' ? 'contactName' : $field);
        }
        return $values;
    }

    /** The selected next step, when this user may read it. */
    private function nextAction(Entity $record): ?array
    {
        $type = $record->get('nextActionType');
        $id = $record->get('nextActionId');
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

    private function reason(?array $nextAction, DateTimeImmutable $now): ?string
    {
        if (!$nextAction || !$nextAction['pending']) return 'noNextAction';
        if (!empty($nextAction['dateEndDate'])) return $nextAction['dateEndDate'] < $now->format('Y-m-d') ? 'overdue' : null;
        if (!empty($nextAction['dateEnd'])) {
            return $nextAction['dateEnd'] < $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s') ? 'overdue' : null;
        }
        return null;
    }

    private function localDue(array $activity, DateTimeImmutable $now): ?string
    {
        if (!empty($activity['dateEndDate'])) return $activity['dateEndDate'];
        return !empty($activity['dateEnd']) ? $this->local($activity['dateEnd'], $now) : null;
    }

    /** A stored UTC timestamp in the reader's zone, so the copy can say "ontem às 18h". */
    private function local(string $utc, DateTimeImmutable $now): string
    {
        return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone($now->getTimezone())->format('Y-m-d H:i');
    }

    private function authorize(): void
    {
        if ((!$this->user->isRegular() && !$this->user->isAdmin()) ||
            !$this->acl->checkScope('Opportunity', 'read') || !$this->acl->checkScope('Opportunity', 'stream') ||
            !$this->acl->checkField('Opportunity', 'name')) throw new Forbidden();
    }

    private function sourceContent(Entity $note): array
    {
        $content = ['text' => mb_substr(strip_tags((string) $note->get('post')), 0, 4000)];
        if ($note->get('opportunityPostDeleted')) return ['text' => ''];
        if ($note->get('type') !== OpportunityStreamEvents::MESSAGE_RECEIVED ||
            !$this->acl->checkScope('ChatwootMessage', 'read') || !$this->acl->checkField('ChatwootMessage', 'content')) return $content;
        $data = (object) $note->get('data');
        $messageId = $data->chatwootMessageId ?? null;
        if (!$messageId) return $content;
        $message = $this->em->getRDBRepository('ChatwootMessage')->where([
            'conversationId' => $note->get('relatedId'), 'chatwootMessageId' => $messageId,
        ])->findOne();
        if (!$message || !$this->acl->checkEntityRead($message)) return $content;
        return [
            'text' => mb_substr(strip_tags((string) $message->get('content')), 0, 4000),
            'conversationId' => $data->chatwootConversationId ?? null,
            'accountId' => $data->chatwootAccountId ?? null,
            'messageId' => $messageId,
        ];
    }

    private function snapshotKey(string $id): string
    {
        return 'catchUp/snapshots/' . hash('sha256', $this->user->getId() . ':' . $id);
    }
}

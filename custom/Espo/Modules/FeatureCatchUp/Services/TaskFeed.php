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
use Espo\Core\Record\ServiceContainer;
use Espo\Core\Utils\Metadata;
use Espo\Entities\User;
use Espo\Modules\Chatwoot\Services\ActivityInbox;
use Espo\Modules\Chatwoot\Tools\Activities\Access;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Query\SelectBuilder;

/**
 * Em dia task cards: this user's pending tasks that are due today or late.
 * Uses the Tasks workspace query, so ACL and team tenancy are the same.
 */
class TaskFeed
{
    private const PAGE_SIZE = 20;
    private const POST_LIMIT = 10;
    private const FIELDS = ['name', 'status', 'dateEnd', 'dateEndDate', 'description', 'parent', 'priority'];

    public function __construct(
        private EntityManager $em,
        private Acl $acl,
        private User $user,
        private Metadata $metadata,
        private ActivityInbox $inbox,
        private Access $access,
        private ServiceContainer $records,
        private Workspace $workspace,
        private Snoozes $snoozes,
    ) {}

    public function list(Request $request): array
    {
        $this->authorize();
        $tenant = $this->workspace->resolve($request)['tenant'];
        $now = Workspace::now($request);
        // "today" covers every pending deadline before the next local day, overdue included.
        $query = $this->inbox->query('Task', $tenant, ['assignee_tab' => 'me', 'due' => 'today', 'timeZone' => $now->getTimezone()->getName()]);
        // The next step of the user's own open deal already has an opportunity card.
        $query->where(['id!=s' => SelectBuilder::create()->from('Opportunity')->select('nextActionId')->where([
            'assignedUserId' => $this->user->getId(), 'nextActionType' => 'Task', 'status!=' => ['Won', 'Lost'], 'nextActionId!=' => null,
        ])->build()]);
        $query->where(['id!=s' => $this->snoozes->active('Task')]);
        $offset = (string) ($request->getQueryParam('cursor') ?? '');
        if ($offset !== '' && !ctype_digit($offset)) throw new BadRequest('Invalid cursor.');
        $limit = Workspace::limit($request, self::PAGE_SIZE);
        $query->select(['id', 'name', 'dateEnd', 'dateEndDate', 'status'])
            ->order([['COALESCE:(dateEndDate, dateEnd)', 'ASC'], ['id', 'ASC']])->limit((int) $offset, $limit + 1);
        $tasks = array_values(iterator_to_array($this->em->getRDBRepository('Task')->clone($query->build())->find()));
        $more = count($tasks) > $limit;
        $tasks = array_slice($tasks, 0, $limit);
        return [
            'items' => array_map(fn (Entity $task) => [
                'id' => $task->getId(), 'type' => 'task', 'name' => $task->get('name'),
                'reason' => $this->dueState($task, $now), 'dateEnd' => $task->get('dateEnd'), 'dateEndDate' => $task->get('dateEndDate'),
            ], $tasks),
            'cursor' => $more ? (string) ((int) $offset + $limit) : null,
        ];
    }

    public function snapshot(Request $request, string $id): array
    {
        $this->authorize();
        ['tenant' => $tenant, 'policy' => $policy] = $this->workspace->resolve($request);
        $now = Workspace::now($request);
        $task = $this->access->record('Task', $id, $tenant);
        $fields = array_values(array_filter(self::FIELDS, fn ($field) => $this->acl->checkField('Task', $field)));
        $facts = ['today' => $now->format('Y-m-d'), 'timeZone' => $now->getTimezone()->getName(), 'dueState' => $this->dueState($task, $now)];
        foreach ($fields as $field) {
            if ($field === 'parent') {
                $facts['parentType'] = $task->get('parentType');
                $facts['parentName'] = $this->parentName($task);
            } elseif ($field === 'description') {
                $facts['description'] = mb_substr(strip_tags((string) $task->get('description')), 0, 1000) ?: null;
            } elseif ($field === 'dateEnd') {
                $facts['dateEnd'] = $task->get('dateEnd') ? $this->local($task->get('dateEnd'), $now) : null;
            } else {
                $facts[$field] = $task->get($field);
            }
        }
        $parent = $this->parentOpportunity($task);
        if ($parent) $facts += $parent['facts'];
        return [
            'id' => $id, 'type' => 'task', 'name' => $task->get('name'), 'tenantId' => $tenant->getId(), 'catchUpPolicy' => $policy,
            'reason' => $facts['dueState'], 'facts' => $facts, 'evidence' => $this->posts($task), 'hasMoreMessages' => false,
            'task' => [
                'status' => $task->get('status'), 'dateEnd' => $task->get('dateEnd'), 'dateEndDate' => $task->get('dateEndDate'),
                'canEdit' => $this->acl->checkEntityEdit($task) && $this->acl->checkField('Task', 'status', 'edit') &&
                    $this->acl->checkField('Task', 'dateEnd', 'edit'),
            ],
            'parent' => $parent ? $parent['card'] : null,
        ];
    }

    public function complete(Request $request, string $id): array
    {
        $status = $this->metadata->get(['scopes', 'Task', 'completedStatusList', 0]) ?? 'Completed';
        return $this->mutate($request, $id, ['status' => $status]);
    }

    public function reschedule(Request $request, string $id): array
    {
        $body = $request->getParsedBody();
        if (is_string($body->dateEndDate ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $body->dateEndDate)) {
            return $this->mutate($request, $id, ['dateEndDate' => $body->dateEndDate, 'dateEnd' => null]);
        }
        if (!is_string($body->dateEnd ?? null)) throw new BadRequest('A new due date is required.');
        try {
            $time = (new DateTimeImmutable($body->dateEnd))->setTimezone(new DateTimeZone('UTC'));
        } catch (\Exception) {
            throw new BadRequest('Invalid due date.');
        }
        return $this->mutate($request, $id, ['dateEnd' => $time->format('Y-m-d H:i:s'), 'dateEndDate' => null]);
    }

    /** A card acts only on the task as it was displayed; anything newer wins. */
    private function mutate(Request $request, string $id, array $data): array
    {
        $this->authorize();
        $tenant = $this->workspace->resolve($request)['tenant'];
        $body = $request->getParsedBody();
        return $this->em->getTransactionManager()->run(function () use ($id, $tenant, $body, $data): array {
            $this->access->record('Task', $id, $tenant);
            $task = $this->em->getRDBRepository('Task')->where(['id' => $id])->forUpdate()->findOne();
            if (!$task || !$this->acl->checkEntityEdit($task) || !$this->acl->checkField('Task', 'status', 'edit') ||
                !$this->acl->checkField('Task', 'dateEnd', 'edit')) throw new Forbidden();
            foreach (['status', 'dateEnd', 'dateEndDate'] as $field) {
                $expected = 'expected' . ucfirst($field);
                if (!property_exists($body, $expected)) throw new BadRequest('The displayed task state is required.');
                if (($task->get($field) ?: null) !== ($body->$expected ?: null)) {
                    throw new Conflict('The task has changed. Refresh this card.');
                }
            }
            if (in_array($task->get('status'), $this->metadata->get(['entityDefs', 'Task', 'fields', 'status', 'notActualOptions']) ?? [], true)) {
                throw new Conflict('The task is already finished.');
            }
            // Normal record update: validation, hooks, stream and ACL as in the Tasks workspace.
            $this->records->get('Task')->update($id, (object) $data);
            return ['id' => $id, ...$data];
        });
    }

    private function dueState(Entity $task, DateTimeImmutable $now): string
    {
        if ($task->get('dateEndDate')) return $task->get('dateEndDate') < $now->format('Y-m-d') ? 'overdue' : 'today';
        $utc = $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        return $task->get('dateEnd') && $task->get('dateEnd') < $utc ? 'overdue' : 'today';
    }

    /** A link-parent name is not a column; read it from the parent the user may see. */
    private function parentName(Entity $task): ?string
    {
        $type = $task->get('parentType');
        if (!$type || !$task->get('parentId') || !$this->acl->checkScope($type, 'read')) return null;
        $parent = $this->em->getEntityById($type, $task->get('parentId'));
        return $parent && $this->acl->checkEntityRead($parent) ? $parent->get('name') : null;
    }

    /** The deal a task moves forward, when the user may read it. */
    private function parentOpportunity(Entity $task): ?array
    {
        if (!$this->acl->checkField('Task', 'parent') || $task->get('parentType') !== 'Opportunity' || !$task->get('parentId') ||
            !$this->acl->checkScope('Opportunity', 'read')) return null;
        $opportunity = $this->em->getEntityById('Opportunity', $task->get('parentId'));
        if (!$opportunity || !$this->acl->checkEntityRead($opportunity)) return null;
        $card = ['id' => $opportunity->getId(), 'name' => $opportunity->get('name')];
        $facts = ['opportunityName' => $opportunity->get('name')];
        foreach (['stage', 'amount', 'amountCurrency', 'status'] as $field) {
            if (!$this->acl->checkField('Opportunity', $field)) continue;
            $card[$field] = $opportunity->get($field);
            $facts['opportunity' . ucfirst($field)] = $opportunity->get($field);
        }
        return ['card' => $card, 'facts' => $facts];
    }

    /** The latest discussion on the task, oldest first, as summary evidence. */
    private function posts(Entity $task): array
    {
        if (!$this->acl->checkEntity($task, 'stream') || !$this->acl->checkScope('Note', 'read')) return [];
        $notes = $this->em->getRDBRepository('Note')->where([
            'parentType' => 'Task', 'parentId' => $task->getId(), 'type' => 'Post', 'opportunityPostDeleted' => false,
        ])->order('number', 'DESC')->limit(0, self::POST_LIMIT)->find();
        $evidence = [];
        foreach ($notes as $note) {
            if (!$this->acl->checkEntityRead($note)) continue;
            array_unshift($evidence, [
                'id' => $note->getId(), 'text' => mb_substr(strip_tags((string) $note->get('post')), 0, 4000),
                'kind' => 'Post', 'at' => $note->get('createdAt'), 'context' => false,
            ]);
        }
        return $evidence;
    }

    private function local(string $utc, DateTimeImmutable $now): string
    {
        return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone($now->getTimezone())->format('Y-m-d H:i');
    }

    private function authorize(): void
    {
        if ((!$this->user->isRegular() && !$this->user->isAdmin()) || !$this->acl->checkScope('Task', 'read') ||
            !$this->acl->checkField('Task', 'name')) throw new Forbidden();
    }
}

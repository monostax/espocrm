<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Services;

use DateTimeImmutable;
use DateTimeZone;
use Espo\Core\Acl;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Conflict;
use Espo\Core\Exceptions\ConflictSilent;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Record\ServiceContainer;
use Espo\Entities\User;
use Espo\Modules\FeatureCatchUp\Services\ChatwootAccess;
use Espo\Modules\Global\Hooks\Opportunity\ClearWatcherFollowupMark;
use Espo\Modules\Global\Tools\NextAction\NextActionWriter;
use Espo\Modules\Global\Tools\Tenant\UserTenantResolver;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\Tools\Stream\NoteUtil;

/**
 * The opportunity watcher's only write path. The backend decides (a decision model, then a planning model); this
 * service applies what was decided, as the AI's own CRM user, and explains it on the deal's stream, or just records
 * what was decided. v1 bodies carry one stage move (`move`); v2 bodies (`act`, 2026-11-22) carry a list of actions:
 * stage, assignee, tasks, next action, AI follow-up, plus a message to the team.
 *
 * Writes go through the ORM, which skips field ACL, the assignment checker and readOnly stripping, so every target
 * (stage, person, task, AI sender) is checked here against the deal's funnel, tenant and teams.
 */
class OpportunityWatcher
{
    /** Config key: when the watcher first reached this installation. Deals older than that start switched off. */
    public const STARTED_AT = 'opportunityWatcherStartedAt';
    public const MOVE = 'move';
    public const ACT = 'act';
    public const OUTCOMES = ['none', 'vetoed', 'noCredits', self::MOVE, self::ACT];
    public const ACTION_KINDS = ['stage', 'assign', 'taskCreate', 'taskUpdate', 'taskComplete', 'taskCancel', 'nextAction', 'followup'];
    /** A stage or assignee a person took back from the watcher is left alone for this long. */
    private const REVERT_DAYS = 7;
    /** Action outcomes the run's mural post lists. */
    private const SHOWN = ['moved', 'applied', 'blocked'];
    private const SAVE = [ClearWatcherFollowupMark::OPTION => true];

    public function __construct(
        private EntityManager $entityManager,
        private User $user,
        private Acl $acl,
        private NoteUtil $noteUtil,
        private ServiceContainer $records,
        private NextActionWriter $nextActions,
        private UserTenantResolver $tenants,
        private ChatwootAccess $chatwootAccess,
    ) {}

    public function decide(string $id, object $body): object
    {
        $opportunity = $this->entityManager->getEntityById('Opportunity', $id);
        if (!$opportunity) throw new NotFound();
        if (!$this->acl->checkEntityEdit($opportunity) || !$this->acl->checkEntityStream($opportunity)) {
            throw new Forbidden('No permission to move this opportunity.');
        }
        $already = $this->entityManager->getRDBRepository('OpportunityWatcherDecision')
            ->where(['runId' => $body->runId, 'opportunityId' => $id])->findOne();
        if ($already) return $this->result($already->get('outcome'), $opportunity, $already->get('actions'));

        if ($body->outcome === self::ACT) return $this->act($opportunity, $body);
        if ($body->outcome !== self::MOVE) {
            $this->log($opportunity, $body, $body->outcome);
            return $this->result($body->outcome, $opportunity);
        }
        $blocked = null;
        try {
            $outcome = $this->entityManager->getTransactionManager()->run(fn () => $this->move($id, $body));
        } catch (ConflictSilent $e) {
            // The stage asks for fields nobody filled in. The move is not ours to force.
            $blocked = json_decode($e->getBody() ?? '', true);
            if (($blocked['code'] ?? null) !== 'opportunityStageRequirements') throw $e;
            $outcome = 'blocked';
        }
        if ($outcome === 'blocked') $this->explainBlocked($opportunity, $body, $blocked);
        $this->log($opportunity, $body, $outcome);
        return $this->result($outcome, $this->entityManager->getEntityById('Opportunity', $id) ?? $opportunity);
    }

    /**
     * A v2 run: each action in its own savepoint, in the order sent, then one post that lists what changed and the
     * decision log, all in one transaction.
     */
    private function act(Entity $opportunity, object $body): object
    {
        $id = $opportunity->getId();
        [$outcome, $results] = $this->entityManager->getTransactionManager()->run(function () use ($opportunity, $body, $id): array {
            $current = $this->lock($id);
            if (!$current || !$current->get('aiWatchEnabled')) {
                $results = array_map(fn (object $action) => ['kind' => $action->kind, 'outcome' => 'off'], $body->actions);
                $this->logRun($opportunity, $body, 'off', $results);
                return ['off', $results];
            }
            // What earlier actions of this run changed that later ones were decided against: steps it cleared.
            $run = (object) ['clearedSteps' => []];
            $results = array_map(fn (object $action) => $this->attempt($id, $action, $body, $run), $body->actions);
            $message = trim((string) ($body->message ?? ''));
            $shown = $this->shown($id, $results);
            if ($shown || $message !== '') $this->postRun($id, $body, $shown, $message);
            $outcome = $this->runOutcome($results, $message !== '');
            $this->logRun($opportunity, $body, $outcome, $results);
            return [$outcome, $results];
        });
        return $this->result($outcome, $this->entityManager->getEntityById('Opportunity', $id) ?? $opportunity, $results);
    }

    /** One action; a refused, stale or blocked one rolls back alone and the run goes on. */
    private function attempt(string $id, object $action, object $body, object $run): array
    {
        $base = ['kind' => $action->kind, 'reason' => trim((string) ($action->reason ?? ''))];
        if ($action->kind === 'stage') $base['toStageId'] = $action->toStageId;
        try {
            $result = $this->entityManager->getTransactionManager()->run(fn (): array => match ($action->kind) {
                'stage' => $this->stage($id, $action),
                'assign' => $this->assign($id, $action),
                'taskCreate' => $this->taskCreate($id, $action, $body),
                'taskUpdate' => $this->taskUpdate($id, $action),
                'taskComplete', 'taskCancel' => $this->taskClose($id, $action, $run),
                'nextAction' => $this->nextAction($id, $action, $run),
                'followup' => $this->followup($id, $action),
            });
        } catch (ConflictSilent $e) {
            $blocked = json_decode($e->getBody() ?? '', true);
            if (($blocked['code'] ?? null) !== 'opportunityStageRequirements') {
                $result = ['outcome' => 'stale'];
            } else {
                // Required fields nobody filled in: not ours to force.
                $result = ['outcome' => 'blocked', 'missingFields' => $this->missingFields($blocked)];
                if ($action->kind === 'stage' && isset($blocked['stageName'])) $result['toStageName'] = $blocked['stageName'];
            }
        } catch (Conflict $e) {
            $result = ['outcome' => 'stale', 'error' => mb_substr($e->getMessage(), 0, 200)];
        } catch (Forbidden|NotFound|BadRequest $e) {
            // What the record services refuse the AI's user is refused here too; the log keeps why.
            $result = ['outcome' => 'refused', 'error' => mb_substr($e->getMessage(), 0, 200) ?: $e::class];
        }
        return [...$base, ...$result];
    }

    private function move(string $id, object $body): string
    {
        $current = $this->lock($id);
        if (!$current || !$current->get('aiWatchEnabled')) return 'off';
        $move = $this->moveStage($current, $body->expectedStageId, $body->toStageId);
        if (is_string($move)) return $move;

        $note = $this->entityManager->getNewEntity('Note');
        $note->set([
            'type' => 'Post',
            'parentType' => 'Opportunity',
            'parentId' => $id,
            'post' => trim($body->reason),
            'isInternal' => true,
            'createdById' => $this->user->getId(),
            'data' => (object) ['opportunityWatcher' => (object) [
                'kind' => 'moved',
                ...$move,
                'probability' => (float) $body->probability,
                'messageWindow' => $body->messageWindow ?? null,
                'runId' => $body->runId,
            ]],
        ]);
        if (!$this->acl->check($note, 'create')) throw new Forbidden('No permission to post in this stream.');
        $this->noteUtil->handlePostText($note);
        $this->entityManager->saveEntity($note);
        return 'moved';
    }

    private function stage(string $id, object $action): array
    {
        $move = $this->moveStage($this->lock($id), $action->expectedStageId, $action->toStageId);
        return is_string($move) ? ['outcome' => $move] : ['outcome' => 'moved', ...$move];
    }

    /**
     * Any other active stage of the deal's funnel (2026-11-22): forward, backward, won, lost, or out of a closed stage,
     * which reopens the deal (the status follows the stage's probability). The backend only offers closed deals it may
     * still reopen. Returns the move, or why there was none.
     */
    private function moveStage(Entity $current, string $expectedStageId, string $toStageId): array|string
    {
        if ($current->get('opportunityStageId') !== $expectedStageId) return 'stale';
        $target = $this->entityManager->getEntityById('OpportunityStage', $toStageId);
        $from = $this->entityManager->getEntityById('OpportunityStage', $expectedStageId);
        if (!$target || !$from || $target->getId() === $from->getId() || !$target->get('isActive')
            || $target->get('funnelId') !== $current->get('funnelId')) {
            return 'stale';
        }
        if ($this->revertedByPerson($current->getId(), $toStageId)) return 'reverted';
        $fromStatus = (string) $current->get('status');
        $current->set('opportunityStageId', $toStageId);
        $this->entityManager->saveEntity($current, self::SAVE);
        return [
            'fromStageId' => $from->getId(),
            'fromStageName' => $from->get('name'),
            'toStageId' => $target->getId(),
            'toStageName' => $target->get('name'),
            'fromStatus' => $fromStatus,
            'toStatus' => (string) $current->get('status'),
        ];
    }

    /**
     * Hands the deal to a person of its tenant and teams who can open its conversations in Chatwoot. Only the deal's
     * assignee changes; the conversation's owner in Chatwoot stays as it is (user decision, 2026-11-22).
     */
    private function assign(string $id, object $action): array
    {
        $current = $this->lock($id);
        $before = $current->get('assignedUserId') ?: null;
        if ($before !== ($action->expectedAssignedUserId ?? null) || $before === $action->userId) return ['outcome' => 'stale'];
        $person = $this->person($action->userId, $current);
        if (!$person) return ['outcome' => 'refused'];
        if ($this->assignmentRevertedByPerson($id, $person->getId())) return ['outcome' => 'reverted', 'userId' => $person->getId()];
        if (!$this->chatwootAccess->forUser($person)->dealsWithOpenConversation([$id])) return ['outcome' => 'refused'];
        $previous = $before ? $this->entityManager->getEntityById(User::ENTITY_TYPE, $before) : null;
        $current->set('assignedUserId', $person->getId());
        $this->entityManager->saveEntity($current, self::SAVE);
        return [
            'outcome' => 'applied',
            'userId' => $person->getId(),
            'userName' => $person->getName(),
            'fromUserName' => $previous?->get('name'),
        ];
    }

    /** A task on the deal for a person of its tenant and teams; the same key in the same run creates it once. */
    private function taskCreate(string $id, object $action, object $body): array
    {
        $deal = $this->lock($id);
        $key = 'watcher:' . $body->runId . ':' . $action->key;
        $task = $this->entityManager->getRDBRepository('Task')->where(['aiTaskKey' => $key])->findOne();
        if (!$task) {
            $assignee = $this->person(($action->assignedUserId ?? null) ?: $deal->get('assignedUserId'), $deal);
            if (!$assignee) return ['outcome' => 'refused'];
            $task = $this->nextActions->createTask($deal, (object) [
                'name' => trim($action->name),
                'description' => $action->description ?? null,
                'assignedUserId' => $assignee->getId(),
                'teamsIds' => $deal->getLinkMultipleIdList('teams'),
                'aiTaskKey' => $key,
                ...$this->due($action, false),
            ]);
        }
        $result = ['outcome' => 'applied', ...$this->taskFacts($task)];
        if ($action->asNextAction ?? false) $result['nextAction'] = $this->select($id, $task);
        return $result;
    }

    private function taskUpdate(string $id, object $action): array
    {
        $task = $this->dealTask($this->lock($id), $action->taskId);
        if (is_string($task)) return ['outcome' => $task];
        $changes = $this->due($action, true);
        if (is_string($action->name ?? null) && trim($action->name) !== '') $changes['name'] = trim($action->name);
        if (!$changes) return ['outcome' => 'stale'];
        $this->records->get('Task')->update($task->getId(), (object) $changes);
        return ['outcome' => 'applied', ...$this->taskFacts($this->entityManager->getEntityById('Task', $task->getId()))];
    }

    /** Completes or cancels a task on the deal; when it was the deal's next action, the step is cleared with it. */
    private function taskClose(string $id, object $action, object $run): array
    {
        $deal = $this->lock($id);
        $task = $this->dealTask($deal, $action->taskId);
        if (is_string($task)) return ['outcome' => $task];
        if ($action->kind === 'taskComplete') {
            $this->nextActions->complete($task);
        } else {
            $this->nextActions->setStatus($task, 'Canceled');
        }
        if ($deal->get('nextActionType') === 'Task' && $deal->get('nextActionId') === $task->getId()) {
            // Activity hooks can advance the deal's version: save the step on a fresh copy.
            $this->nextActions->save($this->lock($id), null, self::SAVE);
            $run->clearedSteps[] = $task->getId();
        }
        return ['outcome' => 'applied', ...$this->taskFacts($task)];
    }

    private function nextAction(string $id, object $action, object $run): array
    {
        $deal = $this->lock($id);
        $current = $deal->get('nextActionId') ?: null;
        $expected = $action->expectedNextActionId ?? null;
        // A step this run completed or cancelled was cleared by it: the step seen before the run still counts.
        if ($current !== $expected && !($current === null && in_array($expected, $run->clearedSteps, true))) {
            return ['outcome' => 'stale'];
        }
        $task = null;
        if (($action->taskId ?? null) !== null) {
            $task = $this->dealTask($deal, $action->taskId);
            if (is_string($task)) return ['outcome' => $task];
            if ($task->getId() === $deal->get('nextActionId')) return ['outcome' => 'stale'];
        } elseif (!$deal->get('nextActionId')) {
            return ['outcome' => 'stale'];
        }
        return ['outcome' => $this->select($id, $task), ...($task ? $this->taskFacts($task) : [])];
    }

    /**
     * Sets the deal's next action. A person's pending step is theirs: it is replaced only once it is done, e.g. by an
     * earlier action of the same run.
     */
    private function select(string $id, ?Entity $task): string
    {
        $deal = $this->lock($id);
        if ($deal->get('status') !== 'Open') return 'refused';
        if ($this->personStepPending($deal)) return 'blocked_by_person_step';
        $this->nextActions->save($deal, $task, self::SAVE);
        return 'applied';
    }

    /**
     * Schedules or stops the deal's AI follow-up. The watcher's follow-ups are marked so the follow-up agent sends
     * without taking the conversation from its person (user decision, 2026-11-22). The sender AI's own
     * "schedules follow-ups by itself" switch and private-only setting are not checked (user decision): armed
     * follow-ups always send, as when people arm them.
     */
    private function followup(string $id, object $action): array
    {
        $deal = $this->lock($id);
        if ($deal->get('status') !== 'Open') return ['outcome' => 'refused'];
        $timer = $deal->get('followupTimer') ?: null;
        if ($timer !== ($action->expectedFollowupTimer ?? null)) return ['outcome' => 'stale'];
        $at = $action->at ?? null;
        if ($at === null) {
            if ($timer === null) return ['outcome' => 'stale'];
            $deal->set(['followupTimer' => null, 'followupByWatcher' => false]);
            $this->entityManager->saveEntity($deal, self::SAVE);
            return ['outcome' => 'applied', 'at' => null];
        }
        $when = strtotime($at . ' UTC');
        if ($when === false || $when < time() - 300 || $when > time() + 366 * 86400) return ['outcome' => 'refused'];
        $sender = $this->aiMembership($action->senderMembershipId ?? null, $deal);
        if (!$sender) return ['outcome' => 'refused'];
        if ($this->personStepPending($deal)) return ['outcome' => 'blocked_by_person_step'];
        if ($deal->get('nextActionId')) {
            // A finished step still on the deal: clear it, as the UI does before arming.
            $this->nextActions->save($deal, null, self::SAVE);
            $deal = $this->lock($id);
        }
        $deal->set(['followupTimer' => $at, 'followupAiAgentId' => $sender->getId(), 'followupByWatcher' => true]);
        $this->entityManager->saveEntity($deal, self::SAVE);
        if (!$deal->get('followupTimer')) return ['outcome' => 'blocked_by_person_step'];
        return ['outcome' => 'applied', 'at' => $at, 'senderMembershipId' => $sender->getId(), 'senderName' => $sender->get('name')];
    }

    /** An active person (not an API, portal or system user) who can act for the deal's tenant and shares one of its teams. */
    private function person(mixed $userId, Entity $deal): ?User
    {
        if (!is_string($userId) || $userId === '') return null;
        $user = $this->entityManager->getEntityById(User::ENTITY_TYPE, $userId);
        if (!$user instanceof User || !$user->isActive() || !in_array($user->getType(), [User::TYPE_REGULAR, User::TYPE_ADMIN], true)) {
            return null;
        }
        if (!$this->tenants->canActForTenant($user, (string) $deal->get('tenantId'))) return null;
        /** @var \Espo\Core\ORM\Entity $deal */
        return array_intersect($user->getTeamIdList(), $deal->getLinkMultipleIdList('teams')) ? $user : null;
    }

    /** An AI agent of a Chatwoot account of the deal's tenant. */
    private function aiMembership(mixed $membershipId, Entity $deal): ?Entity
    {
        if (!is_string($membershipId) || $membershipId === '') return null;
        $membership = $this->entityManager->getEntityById('ChatwootAccountUserMembership', $membershipId);
        if (!$membership || !$membership->get('isAI')) return null;
        $account = $this->entityManager->getEntityById('ChatwootAccount', (string) $membership->get('chatwootAccountId'));
        return $account && $account->get('tenantId') && $account->get('tenantId') === $deal->get('tenantId') ? $membership : null;
    }

    /** A task of this deal, locked, still to do; or why not. */
    private function dealTask(Entity $deal, mixed $taskId): Entity|string
    {
        if (!is_string($taskId) || $taskId === '') return 'refused';
        $task = $this->entityManager->getRDBRepository('Task')->where([
            'id' => $taskId, 'parentType' => 'Opportunity', 'parentId' => $deal->getId(),
        ])->forUpdate()->findOne();
        if (!$task) return 'refused';
        return $this->nextActions->isPending($task) ? $task : 'stale';
    }

    private function personStepPending(Entity $deal): bool
    {
        $type = $deal->get('nextActionType');
        $id = $deal->get('nextActionId');
        if (!$id || !in_array($type, ['Task', 'Call', 'Meeting'], true)) return false;
        $activity = $this->entityManager->getEntityById($type, $id);
        return $activity && $this->nextActions->isPending($activity);
    }

    /** A date-only deadline or a moment; on update, setting one clears the other. */
    private function due(object $action, bool $replace): array
    {
        if (is_string($action->dueDate ?? null)) {
            return ['dateEndDate' => $action->dueDate, ...($replace ? ['dateEnd' => null] : [])];
        }
        if (is_string($action->dueAt ?? null)) {
            $at = (new DateTimeImmutable($action->dueAt, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            return ['dateEnd' => $at, ...($replace ? ['dateEndDate' => null] : [])];
        }
        return [];
    }

    private function taskFacts(Entity $task): array
    {
        $assignee = $task->get('assignedUserId') ? $this->entityManager->getEntityById(User::ENTITY_TYPE, $task->get('assignedUserId')) : null;
        return [
            'taskId' => $task->getId(),
            'taskName' => $task->get('name'),
            'dueDate' => $task->get('dateEndDate'),
            'dueAt' => $task->get('dateEndDate') ? null : $task->get('dateEnd'),
            'userName' => $assignee?->get('name'),
        ];
    }

    /**
     * The watcher moved the deal into this stage in the last REVERT_DAYS and it is no longer there, and it was not the
     * watcher that moved it out: someone took that move back, so the stage is left alone. A run that moved the stage
     * is logged as `moved`, in v1 and v2 alike.
     */
    private function revertedByPerson(string $id, string $stageId): bool
    {
        $decisions = $this->entityManager->getRDBRepository('OpportunityWatcherDecision');
        $since = date('Y-m-d H:i:s', time() - self::REVERT_DAYS * 86400);
        $movedIn = $decisions->where([
            'opportunityId' => $id, 'toStageId' => $stageId, 'outcome' => 'moved', 'createdAt>=' => $since,
        ])->order('createdAt', 'DESC')->findOne();
        if (!$movedIn) return false;
        $movedOut = $decisions->where([
            'opportunityId' => $id, 'fromStageId' => $stageId, 'outcome' => 'moved', 'createdAt>=' => $movedIn->get('createdAt'),
        ])->findOne();
        return !$movedOut;
    }

    /** Same rule for the assignee: the watcher handed the deal to this person, and someone else took it away since. */
    private function assignmentRevertedByPerson(string $id, string $userId): bool
    {
        $decisions = $this->entityManager->getRDBRepository('OpportunityWatcherDecision');
        $since = date('Y-m-d H:i:s', time() - self::REVERT_DAYS * 86400);
        $assigned = $decisions->where([
            'opportunityId' => $id, 'assignedUserId' => $userId, 'createdAt>=' => $since,
        ])->order('createdAt', 'DESC')->findOne();
        if (!$assigned) return false;
        $reassigned = $decisions->where([
            'opportunityId' => $id, 'assignedUserId!=' => $userId, 'createdAt>=' => $assigned->get('createdAt'),
        ])->findOne();
        return !$reassigned;
    }

    /** What the run's post lists: what changed or was blocked; a blocked stage only the first time (see explainBlocked). */
    private function shown(string $id, array $results): array
    {
        $shown = [];
        foreach ($results as $result) {
            if (!in_array($result['outcome'], self::SHOWN, true)) continue;
            if ($result['kind'] === 'stage' && $result['outcome'] === 'blocked' && $this->toldBlocked($id, $result['toStageId'])) continue;
            $shown[] = $result;
        }
        return $shown;
    }

    private function toldBlocked(string $id, string $stageId): bool
    {
        return (bool) $this->entityManager->getRDBRepository('OpportunityWatcherDecision')->where([
            'opportunityId' => $id, 'toStageId' => $stageId,
            'OR' => [['outcome' => 'blocked'], ['stageOutcome' => 'blocked']],
        ])->findOne();
    }

    private function postRun(string $id, object $body, array $shown, string $message): void
    {
        $reasons = implode("\n", array_values(array_unique(array_filter(array_column($shown, 'reason')))));
        $note = $this->entityManager->getNewEntity('Note');
        $note->set([
            'type' => 'Post',
            'parentType' => 'Opportunity',
            'parentId' => $id,
            // Mentions in the message go through the Note hooks: only people who can follow the deal are notified.
            'post' => $message !== '' ? $message : $reasons,
            'isInternal' => true,
            'createdById' => $this->user->getId(),
            'data' => (object) ['opportunityWatcher' => (object) [
                'kind' => 'actions',
                'actions' => array_map(fn (array $result) => (object) $result, $shown),
                'message' => $message !== '' ? $message : null,
                'probability' => isset($body->probability) ? (float) $body->probability : null,
                'messageWindow' => $body->messageWindow ?? null,
                'runId' => $body->runId,
            ]],
        ]);
        if (!$this->acl->check($note, 'create')) throw new Forbidden('No permission to post in this stream.');
        $this->noteUtil->handlePostText($note);
        $this->entityManager->saveEntity($note);
    }

    /** `moved` when the stage moved, `applied` when anything else changed or the team was written to, else the first outcome. */
    private function runOutcome(array $results, bool $messaged): string
    {
        foreach ($results as $result) {
            if ($result['kind'] === 'stage' && $result['outcome'] === 'moved') return 'moved';
        }
        $outcomes = array_column($results, 'outcome');
        if ($messaged || in_array('applied', $outcomes, true)) return 'applied';
        return $outcomes[0] ?? 'none';
    }

    private function missingFields(array $blocked): array
    {
        return array_values(array_filter(array_map(
            fn ($field) => is_array($field) ? ($field['label'] ?? $field['name'] ?? null) : null,
            $blocked['fields'] ?? [],
        )));
    }

    /** One post per deal and stage, so a deal stuck on a required field isn't nagged on every message. */
    private function explainBlocked(Entity $opportunity, object $body, array $blocked): void
    {
        if ($this->toldBlocked($opportunity->getId(), $body->toStageId)) return;
        $note = $this->entityManager->getNewEntity('Note');
        $note->set([
            'type' => 'Post',
            'parentType' => 'Opportunity',
            'parentId' => $opportunity->getId(),
            'post' => trim($body->reason),
            'isInternal' => true,
            'createdById' => $this->user->getId(),
            'data' => (object) ['opportunityWatcher' => (object) [
                'kind' => 'blocked',
                'toStageId' => $body->toStageId,
                'toStageName' => $blocked['stageName'] ?? null,
                'missingFields' => $this->missingFields($blocked),
                'probability' => (float) $body->probability,
                'messageWindow' => $body->messageWindow ?? null,
                'runId' => $body->runId,
            ]],
        ]);
        if (!$this->acl->check($note, 'create')) return;
        $this->noteUtil->handlePostText($note);
        $this->entityManager->saveEntity($note);
    }

    private function log(Entity $opportunity, object $body, string $outcome): void
    {
        $this->entityManager->createEntity('OpportunityWatcherDecision', [
            'opportunityId' => $opportunity->getId(),
            'tenantId' => $opportunity->get('tenantId'),
            'conversationId' => $body->conversationId ?? null,
            'outcome' => $outcome,
            'fromStageId' => $body->expectedStageId ?? $opportunity->get('opportunityStageId'),
            'toStageId' => $body->toStageId ?? null,
            'stageOutcome' => $body->outcome === self::MOVE ? $outcome : null,
            'probability' => $body->probability ?? null,
            'model' => $body->model,
            'reason' => $body->reason ?? null,
            'details' => $body->details ?? null,
            'runId' => $body->runId,
            'createdAt' => date('Y-m-d H:i:s'),
        ]);
    }

    private function logRun(Entity $opportunity, object $body, string $outcome, array $results): void
    {
        $stageAction = null;
        foreach ($body->actions as $action) {
            if ($action->kind === 'stage') {
                $stageAction = $action;
                break;
            }
        }
        $stage = null;
        $assignedUserId = null;
        foreach ($results as $result) {
            if ($result['kind'] === 'stage') $stage ??= $result;
            if ($result['kind'] === 'assign' && $result['outcome'] === 'applied') $assignedUserId = $result['userId'];
        }
        $this->entityManager->createEntity('OpportunityWatcherDecision', [
            'opportunityId' => $opportunity->getId(),
            'tenantId' => $opportunity->get('tenantId'),
            'conversationId' => $body->conversationId ?? null,
            'outcome' => $outcome,
            'fromStageId' => $stageAction->expectedStageId ?? $opportunity->get('opportunityStageId'),
            'toStageId' => $stageAction->toStageId ?? null,
            'stageOutcome' => $stage['outcome'] ?? null,
            'assignedUserId' => $assignedUserId,
            'actions' => array_map(fn (array $result) => (object) $result, $results),
            'probability' => $body->probability ?? null,
            'model' => $body->model,
            'reason' => $body->reason ?? null,
            'details' => $body->details ?? null,
            'runId' => $body->runId,
            'createdAt' => date('Y-m-d H:i:s'),
        ]);
    }

    private function lock(string $id): ?Entity
    {
        return $this->entityManager->getRDBRepository('Opportunity')->where(['id' => $id])->forUpdate()->findOne();
    }

    /** @param iterable<array|object>|null $actions */
    private function result(string $outcome, Entity $opportunity, ?iterable $actions = null): object
    {
        $result = (object) [
            'outcome' => $outcome,
            'opportunityStageId' => $opportunity->get('opportunityStageId'),
            'status' => $opportunity->get('status'),
        ];
        if ($actions !== null) {
            $result->actions = [];
            foreach ($actions as $action) {
                $action = (object) $action;
                $result->actions[] = (object) ['kind' => $action->kind, 'outcome' => $action->outcome];
            }
        }
        return $result;
    }
}

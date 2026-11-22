<?php

declare(strict_types=1);

namespace tests\integration\Espo\Modules\Chatwoot;

require_once __DIR__ . '/OpportunityWatcherTestCase.php';

use Espo\Entities\User;
use Espo\Modules\FeatureCatchUp\Services\ChatwootAccess;
use Espo\ORM\Entity;

/** Watcher v2 runs (`act`): several actions per run, each in its own savepoint, one post. Same database rules as OpportunityWatcherTest. */
class OpportunityWatcherActionsTest extends OpportunityWatcherTestCase
{
    private User $person;
    private ?object $lastBody = null;

    protected function setUp(): void
    {
        parent::setUp();
        // As in production, the AI's CRM user is an API user: never a person a deal or task can go to.
        $this->ai->set('type', 'api');
        $this->em->saveEntity($this->ai, ['skipHooks' => true]);
        $this->person = $this->user($this->team);
    }

    /** Chatwoot's answer stand-in: only these people can open the deal's conversations. */
    private function access(User ...$allowed): ChatwootAccess
    {
        $ids = array_map(fn (User $user) => $user->getId(), $allowed);
        return new class($ids) extends ChatwootAccess {
            private ?string $userId = null;

            public function __construct(private array $allowed) {}

            public function forUser(User $user): ChatwootAccess
            {
                $copy = clone $this;
                $copy->userId = $user->getId();
                return $copy;
            }

            public function dealsWithOpenConversation(array $dealIds): array
            {
                return in_array($this->userId, $this->allowed, true) ? array_fill_keys($dealIds, true) : [];
            }
        };
    }

    private function actBody(array $actions, array $extra = []): object
    {
        return (object) array_merge([
            'outcome' => 'act', 'runId' => 'run-' . bin2hex(random_bytes(8)), 'model' => 'nace-ai/drex-max-20261016',
            'conversationId' => 77, 'probability' => 0.97,
            'messageWindow' => (object) ['from' => '2026-11-22T17:02:00.000Z', 'to' => '2026-11-22T17:10:00.000Z'],
            'details' => (object) ['mode' => 'write'],
            'actions' => array_map(fn (array $action) => (object) ($action + ['reason' => 'A conversa mostra isso.']), $actions),
        ], $extra);
    }

    private function act(Entity $deal, array $actions, array $extra = [], ?ChatwootAccess $access = null): object
    {
        $this->lastBody = $this->actBody($actions, $extra);
        return $this->service(null, $access ?? $this->access($this->person))->decide($deal->getId(), $this->lastBody);
    }

    private function outcomes(object $result): array
    {
        return array_map(fn (object $action) => $action->outcome, $result->actions);
    }

    private function watcherPosts(string $id): array
    {
        return array_values(array_filter($this->notes($id, 'Post'), fn ($note) => $note->get('data')->opportunityWatcher ?? null));
    }

    private function task(Entity $deal, array $extra = []): Entity
    {
        // Through the hooks, so its teams are saved as the product saves them.
        return $this->em->createEntity('Task', [
            'name' => 'Enviar orçamento', 'status' => 'Planned', 'parentType' => 'Opportunity', 'parentId' => $deal->getId(),
            'assignedUserId' => $this->person->getId(), 'teamsIds' => [$this->team->getId()],
        ] + $extra);
    }

    private function fresh(Entity $entity): Entity
    {
        return $this->em->getEntityById($entity->getEntityType(), $entity->getId());
    }

    private function setStep(Entity $deal, ?Entity $task): void
    {
        $deal = $this->fresh($deal);
        $deal->set(['nextActionId' => $task?->getId(), 'nextActionType' => $task ? 'Task' : null]);
        $this->em->saveEntity($deal);
    }

    /** The logged actions of the last run. */
    private function decisionActions(): array
    {
        $row = $this->em->getRDBRepository('OpportunityWatcherDecision')
            ->where(['runId' => $this->lastBody->runId])->findOne();
        return array_map(fn ($action) => (array) $action, $row->get('actions') ?? []);
    }

    public function testARunAppliesItsActionsInOrderAndExplainsThemInOnePost(): void
    {
        $deal = $this->deal('lead');
        $id = $deal->getId();
        $message = '@' . $this->person->getUserName() . ' a cliente pediu uma ligação amanhã de manhã.';
        $body = $this->actBody([
            ['kind' => 'stage', 'expectedStageId' => $this->stages['lead']->getId(), 'toStageId' => $this->stages['proposal']->getId(), 'reason' => 'Orçamento enviado às 14:07.'],
            ['kind' => 'assign', 'expectedAssignedUserId' => $this->ai->getId(), 'userId' => $this->person->getId(), 'reason' => 'Quem atende a cliente é a Ana.'],
            ['kind' => 'taskCreate', 'key' => 't1', 'name' => 'Ligar para confirmar o horário', 'dueDate' => '2026-12-01', 'asNextAction' => true, 'reason' => 'A cliente pediu uma ligação.'],
        ], ['message' => $message]);
        $service = $this->service(null, $this->access($this->person));
        $result = $service->decide($id, $body);
        self::assertSame('moved', $result->outcome);
        self::assertSame(['moved', 'applied', 'applied'], $this->outcomes($result));

        $after = $this->fresh($deal);
        self::assertSame($this->stages['proposal']->getId(), $after->get('opportunityStageId'));
        self::assertSame($this->person->getId(), $after->get('assignedUserId'));
        $task = $this->em->getRDBRepository('Task')->where(['parentType' => 'Opportunity', 'parentId' => $id])->findOne();
        self::assertSame('Ligar para confirmar o horário', $task->get('name'));
        self::assertSame($this->person->getId(), $task->get('assignedUserId'), 'the new assignee, set by the action before');
        self::assertSame('2026-12-01', $task->get('dateEndDate'));
        self::assertSame('Task', $after->get('nextActionType'));
        self::assertSame($task->getId(), $after->get('nextActionId'));

        $posts = $this->watcherPosts($id);
        self::assertCount(1, $posts);
        self::assertSame($message, $posts[0]->get('post'));
        self::assertTrue($posts[0]->get('isInternal'));
        self::assertSame($this->ai->getId(), $posts[0]->get('createdById'));
        $marker = $posts[0]->get('data')->opportunityWatcher;
        self::assertSame('actions', $marker->kind);
        self::assertSame($message, $marker->message);
        self::assertSame(['stage', 'assign', 'taskCreate'], array_map(fn ($action) => $action->kind, $marker->actions));
        self::assertSame('Proposta enviada', $marker->actions[0]->toStageName);
        self::assertSame('Quem atende a cliente é a Ana.', $marker->actions[1]->reason);
        self::assertSame(1, $this->em->getRDBRepository('Notification')
            ->where(['userId' => $this->person->getId(), 'type' => 'MentionInPost'])->count(), 'the mention notifies the person');

        $row = $this->em->getRDBRepository('OpportunityWatcherDecision')->where(['opportunityId' => $id])->findOne();
        self::assertSame('moved', $row->get('stageOutcome'));
        self::assertSame($this->person->getId(), $row->get('assignedUserId'));
        self::assertCount(3, $row->get('actions'));

        // The same run delivered twice changes nothing.
        $again = $service->decide($id, $body);
        self::assertSame('moved', $again->outcome);
        self::assertSame(['moved', 'applied', 'applied'], $this->outcomes($again));
        self::assertCount(1, $this->watcherPosts($id));
        self::assertSame(1, $this->em->getRDBRepository('Task')->where(['parentType' => 'Opportunity', 'parentId' => $id])->count());
        self::assertSame(['moved'], $this->decisions($id));
    }

    public function testTheDealGoesOnlyToAPersonOfItsTenantAndTeamsWhoCanOpenItsConversations(): void
    {
        $deal = $this->deal('lead');
        $otherTeam = $this->create('Team', ['name' => 'Outro tenant']);
        $this->create('Tenant', ['name' => 'Outro tenant', 'baseUserTeamId' => $otherTeam->getId()]);
        $outsider = $this->user($otherTeam);
        $inactive = $this->user($this->team);
        $inactive->set('isActive', false);
        $this->em->saveEntity($inactive, ['skipHooks' => true]);
        $api = $this->user($this->team);
        $api->set('type', 'api');
        $this->em->saveEntity($api, ['skipHooks' => true]);
        $secondTeam = $this->create('Team', ['name' => 'Segunda equipe']);
        $this->em->getRDBRepository('Tenant')->getRelation($this->tenant, 'otherUserTeams')->relate($secondTeam);
        $elsewhere = $this->user($secondTeam);
        $noChat = $this->user($this->team);

        $assign = fn (User $user) => ['kind' => 'assign', 'expectedAssignedUserId' => $this->ai->getId(), 'userId' => $user->getId()];
        $result = $this->act($deal, array_map($assign, [$outsider, $inactive, $api, $elsewhere, $noChat]), [],
            $this->access($outsider, $inactive, $api, $elsewhere));
        self::assertSame(['refused', 'refused', 'refused', 'refused', 'refused'], $this->outcomes($result));
        self::assertSame('refused', $result->outcome);
        self::assertSame($this->ai->getId(), $this->fresh($deal)->get('assignedUserId'));
        self::assertSame([], $this->watcherPosts($deal->getId()), 'nothing changed, nothing posted');

        // The assignee the decision saw is no longer the deal's.
        $stale = $this->act($deal, [['kind' => 'assign', 'expectedAssignedUserId' => $noChat->getId(), 'userId' => $this->person->getId()]]);
        self::assertSame(['stale'], $this->outcomes($stale));
    }

    public function testAStageOrAssigneeAPersonTookBackIsNotGivenAgain(): void
    {
        $deal = $this->deal('lead');
        $id = $deal->getId();
        $other = $this->user($this->team);
        $third = $this->user($this->team);
        $access = $this->access($this->person, $other, $third);
        self::assertSame(['applied'], $this->outcomes($this->act($deal,
            [['kind' => 'assign', 'expectedAssignedUserId' => $this->ai->getId(), 'userId' => $this->person->getId()]], [], $access)));
        $taken = $this->fresh($deal);
        $taken->set('assignedUserId', $other->getId());
        $this->em->saveEntity($taken);
        self::assertSame(['reverted'], $this->outcomes($this->act($deal,
            [['kind' => 'assign', 'expectedAssignedUserId' => $other->getId(), 'userId' => $this->person->getId()]], [], $access)));
        self::assertSame($other->getId(), $this->fresh($deal)->get('assignedUserId'));
        self::assertSame(['applied'], $this->outcomes($this->act($deal,
            [['kind' => 'assign', 'expectedAssignedUserId' => $other->getId(), 'userId' => $third->getId()]], [], $access)));

        $toProposal = ['kind' => 'stage', 'expectedStageId' => $this->stages['lead']->getId(), 'toStageId' => $this->stages['proposal']->getId()];
        self::assertSame(['moved'], $this->outcomes($this->act($deal, [$toProposal])));
        $back = $this->fresh($deal);
        $back->set('opportunityStageId', $this->stages['lead']->getId());
        $this->em->saveEntity($back);
        self::assertSame(['reverted'], $this->outcomes($this->act($deal, [$toProposal])));
        self::assertSame($this->stages['lead']->getId(), $this->fresh($deal)->get('opportunityStageId'));
    }

    public function testTheNextActionChangesOnlyOnceAPersonsPendingStepIsDone(): void
    {
        $deal = $this->deal('proposal');
        $first = $this->task($deal, ['name' => 'Enviar orçamento']);
        $second = $this->task($deal, ['name' => 'Confirmar pagamento']);
        $step = fn (?Entity $task, ?Entity $expected) => ['kind' => 'nextAction', 'expectedNextActionId' => $expected?->getId(), 'taskId' => $task?->getId()];

        self::assertSame(['applied'], $this->outcomes($this->act($deal, [$step($first, null)])));
        self::assertSame($first->getId(), $this->fresh($deal)->get('nextActionId'));

        // A person's pending step is theirs.
        self::assertSame(['blocked_by_person_step'], $this->outcomes($this->act($deal, [$step($second, $first)])));
        self::assertSame($first->getId(), $this->fresh($deal)->get('nextActionId'));

        // Done first, in the same run: then it is replaced.
        $result = $this->act($deal, [['kind' => 'taskComplete', 'taskId' => $first->getId()], $step($second, $first)]);
        self::assertSame(['applied', 'applied'], $this->outcomes($result));
        self::assertSame('Completed', $this->fresh($first)->get('status'));
        self::assertSame($second->getId(), $this->fresh($deal)->get('nextActionId'));

        // Clearing a pending step is replacing it too; cancelling it clears it.
        self::assertSame(['blocked_by_person_step'], $this->outcomes($this->act($deal, [$step(null, $second)])));
        $result = $this->act($deal, [['kind' => 'taskCancel', 'taskId' => $second->getId()]]);
        self::assertSame(['applied'], $this->outcomes($result));
        self::assertSame('Canceled', $this->fresh($second)->get('status'));
        self::assertNull($this->fresh($deal)->get('nextActionId'));

        // Only the deal's own tasks.
        $elsewhere = $this->task($this->deal('lead'));
        self::assertSame(['refused'], $this->outcomes($this->act($deal, [$step($elsewhere, null)])));

        // A new task as the next action does not push a pending step aside, until that step is done.
        $pending = $this->task($deal, ['name' => 'Agendar avaliação']);
        $this->setStep($deal, $pending);
        $create = fn (string $key) => [
            'kind' => 'taskCreate', 'key' => $key, 'name' => 'Ligar para a cliente', 'assignedUserId' => $this->person->getId(), 'asNextAction' => true,
        ];
        self::assertSame(['applied'], $this->outcomes($this->act($deal, [$create('a')])));
        self::assertSame('blocked_by_person_step', $this->decisionActions()[0]['nextAction']);
        self::assertSame($pending->getId(), $this->fresh($deal)->get('nextActionId'));
        self::assertSame(['applied', 'applied'], $this->outcomes($this->act($deal, [['kind' => 'taskComplete', 'taskId' => $pending->getId()], $create('b')])));
        self::assertSame('applied', $this->decisionActions()[1]['nextAction']);
        self::assertNotSame($pending->getId(), $this->fresh($deal)->get('nextActionId'));
    }

    public function testTasksAreCreatedChangedAndClosedOnlyOnTheDealForItsPeople(): void
    {
        $deal = $this->deal('proposal');
        $task = $this->task($deal);
        $result = $this->act($deal, [['kind' => 'taskUpdate', 'taskId' => $task->getId(), 'name' => 'Enviar orçamento revisado', 'dueAt' => '2026-12-02T13:00:00Z']]);
        self::assertSame(['applied'], $this->outcomes($result));
        $task = $this->fresh($task);
        self::assertSame('Enviar orçamento revisado', $task->get('name'));
        self::assertSame('2026-12-02 13:00:00', $task->get('dateEnd'));

        self::assertSame(['applied'], $this->outcomes($this->act($deal, [['kind' => 'taskUpdate', 'taskId' => $task->getId(), 'dueDate' => '2026-12-03']])));
        self::assertSame('2026-12-03', $this->fresh($task)->get('dateEndDate'));

        self::assertSame(['applied'], $this->outcomes($this->act($deal, [['kind' => 'taskComplete', 'taskId' => $task->getId()]])));
        self::assertSame('Completed', $this->fresh($task)->get('status'));
        self::assertSame(['stale'], $this->outcomes($this->act($deal, [['kind' => 'taskComplete', 'taskId' => $task->getId()]])), 'already done');

        $elsewhere = $this->task($this->deal('lead'));
        self::assertSame(['refused', 'refused'], $this->outcomes($this->act($deal, [
            ['kind' => 'taskCancel', 'taskId' => $elsewhere->getId()],
            ['kind' => 'taskUpdate', 'taskId' => $elsewhere->getId(), 'name' => 'Outro'],
        ])));
        self::assertSame('Planned', $this->fresh($elsewhere)->get('status'));

        // A task is for a person: the deal's assignee here is the AI, and an API user is no person either.
        $api = $this->user($this->team);
        $api->set('type', 'api');
        $this->em->saveEntity($api, ['skipHooks' => true]);
        self::assertSame(['refused', 'refused', 'applied'], $this->outcomes($this->act($deal, [
            ['kind' => 'taskCreate', 'key' => 'k1', 'name' => 'Sem dono'],
            ['kind' => 'taskCreate', 'key' => 'k2', 'name' => 'Para a API', 'assignedUserId' => $api->getId()],
            ['kind' => 'taskCreate', 'key' => 'k3', 'name' => 'Para a Ana', 'assignedUserId' => $this->person->getId()],
        ])));
    }

    public function testTheWatchersFollowUpIsMarkedAndStopsBeingItsOwnWhenSomeoneElseSchedules(): void
    {
        $account = $this->create('ChatwootAccount', ['name' => 'Conta', 'tenantId' => $this->tenant->getId(), 'chatwootAccountId' => 901]);
        $agent = $this->create('ChatwootAccountUserMembership', ['name' => 'IA', 'isAI' => true, 'chatwootAccountId' => $account->getId()]);
        $otherTeam = $this->create('Team', ['name' => 'Outro tenant']);
        $otherTenant = $this->create('Tenant', ['name' => 'Outro tenant', 'baseUserTeamId' => $otherTeam->getId()]);
        $otherAccount = $this->create('ChatwootAccount', ['name' => 'Outra', 'tenantId' => $otherTenant->getId(), 'chatwootAccountId' => 902]);
        $foreign = $this->create('ChatwootAccountUserMembership', ['name' => 'IA de fora', 'isAI' => true, 'chatwootAccountId' => $otherAccount->getId()]);
        $deal = $this->deal('proposal');
        $at = gmdate('Y-m-d H:i:00', time() + 86400);
        $arm = fn (Entity $sender, ?string $expected = null) => ['kind' => 'followup', 'expectedFollowupTimer' => $expected, 'at' => $at, 'senderMembershipId' => $sender->getId()];

        self::assertSame(['refused'], $this->outcomes($this->act($deal, [$arm($foreign)])), 'an AI of another tenant');
        self::assertSame(['applied'], $this->outcomes($this->act($deal, [$arm($agent)])));
        $armed = $this->fresh($deal);
        self::assertSame($at, $armed->get('followupTimer'));
        self::assertSame($agent->getId(), $armed->get('followupAiAgentId'));
        self::assertTrue($armed->get('followupByWatcher'));
        self::assertSame('FollowupActive', $armed->get('followupStatus'));

        // A person schedules another one: it is no longer the watcher's.
        $later = gmdate('Y-m-d H:i:00', time() + 2 * 86400);
        $armed->set('followupTimer', $later);
        $this->em->saveEntity($armed);
        self::assertFalse($this->fresh($deal)->get('followupByWatcher'));

        self::assertSame(['applied'], $this->outcomes($this->act($deal, [['kind' => 'followup', 'expectedFollowupTimer' => $later, 'at' => null]])));
        self::assertNull($this->fresh($deal)->get('followupTimer'));

        // A person's pending step keeps the follow-up off, unless the run finishes the step first.
        $pending = $this->task($deal);
        $this->setStep($deal, $pending);
        self::assertSame(['blocked_by_person_step'], $this->outcomes($this->act($deal, [$arm($agent)])));
        self::assertNull($this->fresh($deal)->get('followupTimer'));
        self::assertSame(['applied', 'applied'], $this->outcomes($this->act($deal, [['kind' => 'taskComplete', 'taskId' => $pending->getId()], $arm($agent)])));
        self::assertSame($at, $this->fresh($deal)->get('followupTimer'));

        // Never on a closed deal.
        $closed = $this->deal('won');
        self::assertSame(['refused'], $this->outcomes($this->act($closed, [$arm($agent)])));
    }

    public function testABlockedActionRollsBackAloneAndIsToldOnce(): void
    {
        $this->create('CustomFieldDef', [
            'name' => 'convenio', 'label' => 'Convênio', 'entityType' => 'Opportunity', 'type' => 'varchar', 'valueKey' => 'convenio',
            'tenantId' => $this->tenant->getId(), 'teamsIds' => [$this->team->getId()], 'isActive' => true,
        ]);
        $this->stages['won']->set('requiredCustomFieldKeys', ['convenio']);
        $this->em->saveEntity($this->stages['won'], ['skipHooks' => true]);
        $deal = $this->deal('proposal');
        $id = $deal->getId();
        $toWon = ['kind' => 'stage', 'expectedStageId' => $this->stages['proposal']->getId(), 'toStageId' => $this->stages['won']->getId()];

        $result = $this->act($deal, [$toWon, ['kind' => 'assign', 'expectedAssignedUserId' => $this->ai->getId(), 'userId' => $this->person->getId()]]);
        self::assertSame(['blocked', 'applied'], $this->outcomes($result));
        self::assertSame('applied', $result->outcome);
        $after = $this->fresh($deal);
        self::assertSame($this->stages['proposal']->getId(), $after->get('opportunityStageId'));
        self::assertSame($this->person->getId(), $after->get('assignedUserId'));
        $marker = $this->watcherPosts($id)[0]->get('data')->opportunityWatcher;
        self::assertSame(['blocked', 'applied'], array_map(fn ($action) => $action->outcome, $marker->actions));
        self::assertSame(['Convênio'], $marker->actions[0]->missingFields);

        // The same block again is not posted again.
        $again = $this->act($deal, [$toWon]);
        self::assertSame('blocked', $again->outcome);
        self::assertCount(1, $this->watcherPosts($id));
    }

    public function testASwitchedOffDealIsLeftAloneAndAMessageAloneIsPosted(): void
    {
        $deal = $this->deal('lead');
        $message = 'A cliente pediu para falar com uma pessoa sobre o desconto.';
        $result = $this->act($deal, [], ['message' => $message]);
        self::assertSame('applied', $result->outcome);
        self::assertSame($message, $this->watcherPosts($deal->getId())[0]->get('post'));

        $off = $this->fresh($deal);
        $off->set('aiWatchEnabled', false);
        $this->em->saveEntity($off);
        $result = $this->act($deal, [['kind' => 'stage', 'expectedStageId' => $this->stages['lead']->getId(), 'toStageId' => $this->stages['proposal']->getId()]],
            ['message' => $message]);
        self::assertSame('off', $result->outcome);
        self::assertSame(['off'], $this->outcomes($result));
        self::assertSame($this->stages['lead']->getId(), $this->fresh($deal)->get('opportunityStageId'));
        self::assertCount(1, $this->watcherPosts($deal->getId()));
    }
}

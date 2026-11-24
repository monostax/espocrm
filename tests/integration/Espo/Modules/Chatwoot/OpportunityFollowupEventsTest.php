<?php

declare(strict_types=1);

namespace tests\integration\Espo\Modules\Chatwoot;

require_once __DIR__ . '/OpportunityWatcherTestCase.php';

use Espo\Core\Application;
use Espo\Core\Application\ApplicationParams;
use Espo\Core\Exceptions\ConflictSilent;
use Espo\Entities\User;
use Espo\Modules\Chatwoot\Services\OpportunityFollowupEvents;
use Espo\Modules\FeatureCatchUp\Services\ChatwootAccess;
use Espo\ORM\Entity;

/**
 * 2026-11-23: a sent AI follow-up leaves a pill on the deal, a customer reply undoes the "no reply" step, and the
 * watcher's follow-up and next step still save on a deal missing required fields. Same database rules as
 * OpportunityWatcherTest (ESPO_WATCHER_TEST=1, disposable data/config.php).
 */
class OpportunityFollowupEventsTest extends OpportunityWatcherTestCase
{
    private User $person;
    private Entity $account;
    private Entity $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ai->set('type', 'api');
        $this->em->saveEntity($this->ai, ['skipHooks' => true]);
        $this->person = $this->user($this->team);
        $this->account = $this->create('ChatwootAccount', [
            'name' => 'Conta', 'tenantId' => $this->tenant->getId(), 'chatwootAccountId' => random_int(100000, 999999),
        ]);
        $this->agent = $this->create('ChatwootAccountUserMembership', [
            'name' => '✦ IA (Assistente Erivania)', 'isAI' => true, 'chatwootAccountId' => $this->account->getId(),
        ]);
    }

    private function access(): ChatwootAccess
    {
        $allowed = [$this->person->getId()];
        return new class($allowed) extends ChatwootAccess {
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

    private function act(Entity $deal, array $actions, ?string $runId = null): object
    {
        return $this->service(null, $this->access())->decide($deal->getId(), (object) [
            'outcome' => 'act', 'runId' => $runId ?? 'run-' . bin2hex(random_bytes(8)), 'model' => 'hitl-escalation',
            'conversationId' => 77,
            'actions' => array_map(fn (array $action) => (object) ($action + ['reason' => 'Última tentativa enviada.']), $actions),
        ]);
    }

    private function events(?User $actor = null): OpportunityFollowupEvents
    {
        $services = $actor ? ['user' => $this->em->getEntityById('User', $actor->getId())] : [];
        $app = new Application(new ApplicationParams(noErrorHandler: true, services: $services));
        if (!$actor) $app->setupSystemUser();
        return $app->getInjectableFactory()->create(OpportunityFollowupEvents::class);
    }

    private function fresh(Entity $entity): Entity
    {
        return $this->em->getEntityById($entity->getEntityType(), $entity->getId());
    }

    private function setFollowup(Entity $deal, ?string $timer, int $count): void
    {
        $deal = $this->fresh($deal);
        $deal->set([
            'followupTimer' => $timer, 'followupCount' => $count, 'followupAiAgentId' => $this->agent->getId(),
            'followupStatus' => $timer ? 'FollowupActive' : 'FollowupInactive',
        ]);
        $this->em->saveEntity($deal, ['skipHooks' => true]);
    }

    private function noReplyTask(Entity $deal, string $tag): Entity
    {
        $result = $this->act($deal, [[
            'kind' => 'taskCreate', 'key' => 'hitl', 'name' => 'Cliente não respondeu ao follow-up: retomar contato ou encerrar',
            'description' => null, 'dueDate' => null, 'dueAt' => gmdate('c', time() + 86400),
            'assignedUserId' => $this->person->getId(), 'asNextAction' => true,
        ]], 'followup-no-reply:' . $deal->getId() . ':' . $tag);
        self::assertSame(['applied'], array_map(fn ($action) => $action->outcome, $result->actions));
        $task = $this->em->getRDBRepository('Task')
            ->where(['aiTaskKey' => 'watcher:followup-no-reply:' . $deal->getId() . ':' . $tag . ':hitl'])->findOne();
        self::assertNotNull($task);
        return $task;
    }

    public function testAStoppedFollowUpLeavesOneNotSentPillWithTheReason(): void
    {
        $deal = $this->deal('proposal');
        $conversation = $this->create('ChatwootConversation', [
            'name' => '#77', 'chatwootAccountId' => $this->account->getId(), 'chatwootConversationId' => 77,
        ]);
        $this->em->getRDBRepository('ChatwootConversation')->getRelation($conversation, 'opportunities')->relate($deal);
        $platformId = (int) $this->account->get('chatwootAccountId');
        $this->setFollowup($deal, null, 0);

        $first = $this->events($this->ai)->notSent($deal->getId(), 'whatsappWindowClosed', '2026-10-27 23:32:00', 'run-1', $platformId, 77);
        self::assertSame($first->id, $this->events($this->ai)->notSent($deal->getId(), 'whatsappWindowClosed', null, 'run-1', $platformId, 77)->id,
            'once per follow-up run');
        $pills = $this->notes($deal->getId(), OpportunityFollowupEvents::NOT_SENT_TYPE);
        self::assertCount(1, $pills);
        $data = $pills[0]->get('data');
        self::assertSame(['whatsappWindowClosed', '2026-10-27 23:32:00', '✦ IA (Assistente Erivania)'], [$data->reason, $data->windowClosedAt, $data->aiAgentName]);
        self::assertSame([$platformId, 77], [$data->chatwootAccountId, $data->chatwootConversationId]);
        self::assertSame($this->ai->getId(), $pills[0]->get('createdById'));
        self::assertTrue($pills[0]->get('isInternal'));
        self::assertCount(0, $this->notes($deal->getId(), OpportunityFollowupEvents::TYPE), 'never counted as a sent follow-up');
    }

    public function testEachSentFollowUpLeavesOnePillThatSaysWhenItWasTheLast(): void
    {
        $deal = $this->deal('proposal');
        $conversation = $this->create('ChatwootConversation', [
            'name' => '#77', 'chatwootAccountId' => $this->account->getId(), 'chatwootConversationId' => 77,
        ]);
        $this->em->getRDBRepository('ChatwootConversation')->getRelation($conversation, 'opportunities')->relate($deal);
        $platformId = (int) $this->account->get('chatwootAccountId');

        $this->setFollowup($deal, gmdate('Y-m-d H:i:00', time() + 3600), 1);
        self::assertFalse($this->events($this->ai)->sent($deal->getId(), $platformId, 77, 5001)->last);

        $this->setFollowup($deal, null, 2);
        $last = $this->events($this->ai)->sent($deal->getId(), $platformId, 77, 5002);
        self::assertTrue($last->last);
        self::assertSame($last->id, $this->events($this->ai)->sent($deal->getId(), $platformId, 77, 5002)->id, 'once per message');

        $pills = $this->notes($deal->getId(), OpportunityFollowupEvents::TYPE);
        self::assertCount(2, $pills);
        $data = $pills[1]->get('data');
        self::assertSame([true, 2, '✦ IA (Assistente Erivania)'], [$data->last, $data->attempt, $data->aiAgentName]);
        self::assertSame([$platformId, 77, 5002], [$data->chatwootAccountId, $data->chatwootConversationId, $data->chatwootMessageId]);
        self::assertSame($this->ai->getId(), $pills[1]->get('createdById'));
        self::assertTrue($pills[1]->get('isInternal'));

        // A conversation that is not the deal's is never linked from its mural.
        $this->events($this->ai)->sent($deal->getId(), $platformId, 78, 5003);
        $unlinked = $this->notes($deal->getId(), OpportunityFollowupEvents::TYPE)[2]->get('data');
        self::assertObjectNotHasProperty('chatwootConversationId', $unlinked);
    }

    public function testACustomerReplyCancelsTheNoReplyStepAndLetsTheAiFollowUpAgain(): void
    {
        $deal = $this->deal('proposal');
        $own = $this->em->createEntity('Task', [
            'name' => 'Ligar para a paciente', 'status' => 'Planned', 'parentType' => 'Opportunity', 'parentId' => $deal->getId(),
            'assignedUserId' => $this->person->getId(), 'teamsIds' => [$this->team->getId()],
        ]);
        $task = $this->noReplyTask($deal, '1');
        self::assertSame($task->getId(), $this->fresh($deal)->get('nextActionId'));
        $this->setFollowup($deal, null, 1);

        // History being synced is not the customer answering now.
        $this->events()->customerReplied($deal, gmdate('Y-m-d H:i:s', time() - 7200));
        self::assertSame('Planned', $this->fresh($task)->get('status'));
        self::assertSame(1, $this->fresh($deal)->get('followupCount'));

        $this->events()->customerReplied($deal, gmdate('Y-m-d H:i:s', time() + 2));
        self::assertSame('Canceled', $this->fresh($task)->get('status'));
        self::assertSame('Planned', $this->fresh($own)->get('status'), "a person's own task stays");
        $after = $this->fresh($deal);
        self::assertNull($after->get('nextActionId'));
        self::assertSame(0, $after->get('followupCount'));
    }

    public function testAReplyFromBeforeTheTaskOrAFollowUpSentAfterItChangesNothing(): void
    {
        $deal = $this->deal('proposal');
        $task = $this->noReplyTask($deal, '2');
        $this->setFollowup($deal, null, 1);
        $this->events($this->ai)->sent($deal->getId(), (int) $this->account->get('chatwootAccountId'), 77, 6001);

        $this->events()->customerReplied($deal, gmdate('Y-m-d H:i:s', time() - 600));
        self::assertSame('Planned', $this->fresh($task)->get('status'));
        self::assertSame(1, $this->fresh($deal)->get('followupCount'), 'a follow-up went out after this reply');
    }

    public function testClosingADealCancelsTheOpenTasksAnAiMadeWhoeverClosesIt(): void
    {
        // A person marks the deal Lost.
        $deal = $this->deal('proposal');
        $ai = $this->noReplyTask($deal, '4');
        $own = $this->em->createEntity('Task', [
            'name' => 'Emitir nota fiscal', 'status' => 'Planned', 'parentType' => 'Opportunity', 'parentId' => $deal->getId(),
            'assignedUserId' => $this->person->getId(), 'teamsIds' => [$this->team->getId()],
        ]);
        $done = $this->noReplyTask($deal, '5');
        $done->set('status', 'Completed');
        $this->em->saveEntity($done);
        $closing = $this->fresh($deal);
        $closing->set('opportunityStageId', $this->stages['lost']->getId());
        $this->em->saveEntity($closing);
        $after = $this->fresh($deal);
        self::assertSame('Lost', $after->get('status'));
        self::assertSame('Canceled', $this->fresh($ai)->get('status'));
        self::assertSame('Planned', $this->fresh($own)->get('status'), "a person's task may be post-sale work");
        self::assertSame('Completed', $this->fresh($done)->get('status'));
        self::assertNull($after->get('nextActionId'));

        // The watcher marks another deal Won.
        $won = $this->deal('proposal');
        $task = $this->noReplyTask($won, '6');
        $move = $this->act($won, [['kind' => 'stage', 'expectedStageId' => $this->stages['proposal']->getId(), 'toStageId' => $this->stages['won']->getId()]]);
        self::assertSame(['moved'], array_map(fn ($action) => $action->outcome, $move->actions));
        self::assertSame('Canceled', $this->fresh($task)->get('status'));
        self::assertNull($this->fresh($won)->get('nextActionId'));
    }

    public function testTheWatchersFollowUpAndStepSaveOnADealMissingRequiredFieldsButStageMovesDoNot(): void
    {
        $deal = $this->deal('proposal');
        // A field made required after the deal existed, as on Nowle's deals.
        $this->create('CustomFieldDef', [
            'name' => 'produto', 'label' => 'Produto', 'entityType' => 'Opportunity', 'type' => 'varchar', 'valueKey' => 'produto',
            'tenantId' => $this->tenant->getId(), 'teamsIds' => [$this->team->getId()], 'isActive' => true, 'isRequired' => true,
        ]);
        try {
            $edit = $this->fresh($deal);
            $edit->set('name', 'Ressonância - Maria (editado)');
            $this->em->saveEntity($edit);
            self::fail('A person editing the deal still has to fill the field.');
        } catch (ConflictSilent) {
            $this->addToAssertionCount(1);
        }

        $at = gmdate('Y-m-d H:i:00', time() + 86400);
        $arm = $this->act($deal, [['kind' => 'followup', 'expectedFollowupTimer' => null, 'at' => $at, 'senderMembershipId' => $this->agent->getId()]]);
        self::assertSame(['applied'], array_map(fn ($action) => $action->outcome, $arm->actions));
        self::assertSame($at, $this->fresh($deal)->get('followupTimer'));

        $task = $this->noReplyTask($deal, '3');
        self::assertSame($task->getId(), $this->fresh($deal)->get('nextActionId'));

        $move = $this->act($deal, [['kind' => 'stage', 'expectedStageId' => $this->stages['proposal']->getId(), 'toStageId' => $this->stages['won']->getId()]]);
        self::assertSame(['blocked'], array_map(fn ($action) => $action->outcome, $move->actions));
        self::assertSame($this->stages['proposal']->getId(), $this->fresh($deal)->get('opportunityStageId'));
    }
}

<?php

declare(strict_types=1);

namespace tests\integration\Espo\Modules\Chatwoot;

use Espo\Core\Application;
use Espo\Core\Application\ApplicationParams;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Utils\Database\Schema\SchemaManager;
use Espo\Entities\User;
use Espo\Core\Utils\Config\ConfigWriter;
use Espo\Modules\Chatwoot\Rebuild\StartOpportunityWatcher;
use Espo\Modules\Chatwoot\Services\OpportunityWatcher;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use PHPUnit\Framework\TestCase;

/** Uses a disposable installed database (data/config.php), real ACL, Opportunity and Note hooks, and transactions. */
class OpportunityWatcherTest extends TestCase
{
    private static bool $schemaReady = false;
    private Application $app;
    private EntityManager $em;
    private Entity $tenant;
    private Entity $team;
    private Entity $role;
    private User $ai;
    private Entity $funnel;
    /** @var array<string, Entity> */
    private array $stages = [];

    protected function setUp(): void
    {
        if (getenv('ESPO_WATCHER_TEST') !== '1') {
            self::markTestSkipped('Set ESPO_WATCHER_TEST=1 only with a disposable data/config.php database.');
        }
        $this->app = new Application(new ApplicationParams(noErrorHandler: true));
        if (!self::$schemaReady) {
            $this->app->getInjectableFactory()->create(SchemaManager::class)->rebuild();
            self::$schemaReady = true;
        }
        $this->em = $this->app->getContainer()->getByClass(EntityManager::class);
        if (!$this->em->getRDBRepository('User')->where(['userName' => 'system'])->findOne()) {
            $this->create('User', ['userName' => 'system', 'type' => 'system', 'isActive' => true]);
        }
        $this->app->setupSystemUser();
        $this->team = $this->create('Team', ['name' => 'Watcher test']);
        $this->tenant = $this->create('Tenant', ['name' => 'Watcher test', 'baseUserTeamId' => $this->team->getId()]);
        $this->role = $this->create('Role', ['name' => 'Watcher test', 'data' => (object) [
            'Opportunity' => (object) ['read' => 'all', 'stream' => 'all', 'create' => 'yes', 'edit' => 'all', 'delete' => 'no'],
            'OpportunityStage' => (object) ['read' => 'all'],
            'Funnel' => (object) ['read' => 'all'],
            'Note' => (object) ['read' => 'all', 'create' => 'yes', 'edit' => 'own', 'delete' => 'own'],
            'User' => (object) ['read' => 'all'],
            'Team' => (object) ['read' => 'all'],
        ]]);
        $this->ai = $this->user($this->team);
        $this->funnel = $this->create('Funnel', ['name' => 'Orçamentos', 'tenantId' => $this->tenant->getId(), 'isActive' => true]);
        foreach ([['lead', 'Novo lead', 10, 10], ['proposal', 'Proposta enviada', 20, 50], ['won', 'Ganho', 30, 100], ['lost', 'Perdido', 40, 0]]
            as [$key, $name, $order, $probability]) {
            $this->stages[$key] = $this->create('OpportunityStage', [
                'name' => $name, 'order' => $order, 'probability' => $probability, 'funnelId' => $this->funnel->getId(), 'isActive' => true,
            ]);
        }
    }

    private function create(string $type, array $data): Entity
    {
        return $this->em->createEntity($type, $data, ['skipHooks' => true]);
    }

    private function user(Entity $team): User
    {
        $user = $this->create('User', [
            'userName' => 'watcher-' . bin2hex(random_bytes(6)), 'type' => 'regular', 'isActive' => true,
        ]);
        $this->em->getRDBRepository('User')->getRelation($user, 'teams')->relate($team);
        $this->em->getRDBRepository('User')->getRelation($user, 'roles')->relate($this->role);
        return $this->em->getEntityById('User', $user->getId());
    }

    private function service(?User $actor = null): OpportunityWatcher
    {
        $actor = $this->em->getEntityById('User', ($actor ?? $this->ai)->getId());
        return (new Application(new ApplicationParams(noErrorHandler: true, services: ['user' => $actor])))
            ->getInjectableFactory()->create(OpportunityWatcher::class);
    }

    private function deal(string $stage = 'lead', array $extra = []): Entity
    {
        // Created through the hooks, as the product creates deals: status and stage timing start out consistent.
        $app = new Application(new ApplicationParams(noErrorHandler: true, services: ['user' => $this->em->getEntityById('User', $this->ai->getId())]));
        $deal = $app->getContainer()->getByClass(EntityManager::class)->createEntity('Opportunity', [
            'name' => 'Ressonância - Maria', 'tenantId' => $this->tenant->getId(), 'assignedUserId' => $this->ai->getId(),
            'teamsIds' => [$this->team->getId()], 'funnelId' => $this->funnel->getId(),
            'opportunityStageId' => $this->stages[$stage]->getId(), 'amount' => 350, 'closeDate' => date('Y-m-d'),
        ] + $extra);
        return $this->em->getEntityById('Opportunity', $deal->getId());
    }

    private function body(string $outcome, string $from, ?string $to, array $extra = []): object
    {
        return (object) array_merge([
            'outcome' => $outcome, 'runId' => 'run-' . bin2hex(random_bytes(8)), 'model' => 'nace-ai/drex-max-20261016',
            'conversationId' => 77, 'expectedStageId' => $this->stages[$from]->getId(),
            'toStageId' => $to ? $this->stages[$to]->getId() : null,
            'reason' => 'A cliente confirmou o horário e enviou o comprovante do Pix às 14:07.', 'probability' => 0.97,
            'messageWindow' => (object) ['from' => '2026-11-17T17:02:00.000Z', 'to' => '2026-11-17T17:10:00.000Z'],
            'details' => (object) ['mode' => 'write'],
        ], $extra);
    }

    private function notes(string $id, string $type): array
    {
        return iterator_to_array($this->em->getRDBRepository('Note')
            ->where(['parentType' => 'Opportunity', 'parentId' => $id, 'type' => $type])->order('number')->find());
    }

    private function decisions(string $id): array
    {
        return array_map(fn ($row) => $row->get('outcome'), iterator_to_array(
            $this->em->getRDBRepository('OpportunityWatcherDecision')->where(['opportunityId' => $id])->order('createdAt')->find()));
    }

    public function testNewDealsAreWatchedByDefault(): void
    {
        self::assertTrue($this->deal()->get('aiWatchEnabled'));
    }

    public function testDealsThatExistedBeforeTheWatcherStartSwitchedOffOnce(): void
    {
        $fresh = fn () => new Application(new ApplicationParams(noErrorHandler: true));
        $writer = $fresh()->getInjectableFactory()->create(ConfigWriter::class);
        $writer->remove(OpportunityWatcher::STARTED_AT);
        $writer->save();
        $old = $this->deal('proposal');
        self::assertTrue($old->get('aiWatchEnabled'));

        $fresh()->getInjectableFactory()->create(StartOpportunityWatcher::class)->process();
        self::assertFalse($this->em->getEntityById('Opportunity', $old->getId())->get('aiWatchEnabled'));
        self::assertNotEmpty($fresh()->getContainer()->getByClass(\Espo\Core\Utils\Config::class)->get(OpportunityWatcher::STARTED_AT));
        self::assertSame('off', $this->service()->decide($old->getId(), $this->body('move', 'proposal', 'won'))->outcome);

        // From then on: new deals are watched, a person can switch an old one on, and later rebuilds leave both alone.
        $new = $this->deal('proposal');
        $switchedOn = $this->em->getEntityById('Opportunity', $old->getId());
        $switchedOn->set('aiWatchEnabled', true);
        $this->em->saveEntity($switchedOn);
        $fresh()->getInjectableFactory()->create(StartOpportunityWatcher::class)->process();
        self::assertTrue($this->em->getEntityById('Opportunity', $new->getId())->get('aiWatchEnabled'));
        self::assertTrue($this->em->getEntityById('Opportunity', $old->getId())->get('aiWatchEnabled'));
    }

    public function testAMoveChangesTheStageExplainsItOnTheStreamAndIsLoggedOnce(): void
    {
        $deal = $this->deal('proposal');
        $body = $this->body('move', 'proposal', 'won');
        $result = $this->service()->decide($deal->getId(), $body);
        self::assertSame('moved', $result->outcome);
        self::assertSame('Won', $result->status);

        $moved = $this->em->getEntityById('Opportunity', $deal->getId());
        self::assertSame($this->stages['won']->getId(), $moved->get('opportunityStageId'));
        self::assertSame('Won', $moved->get('status'));
        self::assertSame('FollowupInactive', $moved->get('followupStatus'));

        $posts = array_values(array_filter($this->notes($deal->getId(), 'Post'), fn ($note) => $note->get('data')->opportunityWatcher ?? null));
        self::assertCount(1, $posts);
        self::assertSame($body->reason, $posts[0]->get('post'));
        self::assertTrue($posts[0]->get('isInternal'));
        self::assertSame($this->ai->getId(), $posts[0]->get('createdById'));
        $marker = $posts[0]->get('data')->opportunityWatcher;
        self::assertSame('moved', $marker->kind);
        self::assertSame('Proposta enviada', $marker->fromStageName);
        self::assertSame('Ganho', $marker->toStageName);
        self::assertSame(0.97, $marker->probability);

        $pills = $this->notes($deal->getId(), 'OpportunityStageChanged');
        self::assertCount(1, $pills);
        self::assertSame('Ganho', $pills[0]->get('data')->toStageName);
        self::assertSame('Proposta enviada', $pills[0]->get('data')->fromStageName);
        self::assertSame($this->ai->getId(), $pills[0]->get('createdById'));

        // The same run delivered twice changes nothing.
        self::assertSame('moved', $this->service()->decide($deal->getId(), $body)->outcome);
        self::assertSame(['moved'], $this->decisions($deal->getId()));
        self::assertCount(1, $this->notes($deal->getId(), 'OpportunityStageChanged'));
    }

    public function testASwitchedOffDealIsLeftAlone(): void
    {
        $deal = $this->deal('proposal');
        $deal->set('aiWatchEnabled', false);
        $this->em->saveEntity($deal);
        self::assertSame('off', $this->service()->decide($deal->getId(), $this->body('move', 'proposal', 'won'))->outcome);
        self::assertSame($this->stages['proposal']->getId(), $this->em->getEntityById('Opportunity', $deal->getId())->get('opportunityStageId'));
        self::assertSame(['off'], $this->decisions($deal->getId()));
    }

    public function testOnlyForwardMovesFromTheExpectedOpenStageAreApplied(): void
    {
        $deal = $this->deal('proposal');
        $id = $deal->getId();
        // The deal is no longer where the decision saw it.
        self::assertSame('stale', $this->service()->decide($id, $this->body('move', 'lead', 'proposal'))->outcome);
        // Never backwards.
        self::assertSame('stale', $this->service()->decide($id, $this->body('move', 'proposal', 'lead'))->outcome);
        // A stage of another funnel.
        $other = $this->create('Funnel', ['name' => 'Outro', 'tenantId' => $this->tenant->getId(), 'isActive' => true]);
        $foreign = $this->create('OpportunityStage', ['name' => 'X', 'order' => 90, 'probability' => 100, 'funnelId' => $other->getId(), 'isActive' => true]);
        self::assertSame('stale', $this->service()->decide($id, $this->body('move', 'proposal', null, ['toStageId' => $foreign->getId()]))->outcome);
        self::assertSame($this->stages['proposal']->getId(), $this->em->getEntityById('Opportunity', $id)->get('opportunityStageId'));

        // Lost closes it; a closed deal is never moved again.
        self::assertSame('moved', $this->service()->decide($id, $this->body('move', 'proposal', 'lost'))->outcome);
        self::assertSame('Lost', $this->em->getEntityById('Opportunity', $id)->get('status'));
        self::assertSame('stale', $this->service()->decide($id, $this->body('move', 'lost', 'won'))->outcome);
    }

    public function testDecisionsThatMoveNothingAreOnlyLogged(): void
    {
        $deal = $this->deal('lead');
        foreach (['none', 'vetoed', 'noCredits'] as $outcome) {
            self::assertSame($outcome, $this->service()->decide($deal->getId(), $this->body($outcome, 'lead', 'proposal'))->outcome);
        }
        self::assertSame($this->stages['lead']->getId(), $this->em->getEntityById('Opportunity', $deal->getId())->get('opportunityStageId'));
        self::assertSame([], $this->notes($deal->getId(), 'OpportunityStageChanged'));
        self::assertEqualsCanonicalizing(['none', 'vetoed', 'noCredits'], $this->decisions($deal->getId()));
    }

    public function testAStageAPersonMovedTheDealBackOutOfIsNotEnteredAgain(): void
    {
        $deal = $this->deal('lead');
        $id = $deal->getId();
        self::assertSame('moved', $this->service()->decide($id, $this->body('move', 'lead', 'proposal'))->outcome);
        $back = $this->em->getEntityById('Opportunity', $id);
        $back->set('opportunityStageId', $this->stages['lead']->getId());
        $this->em->saveEntity($back);
        self::assertCount(2, $this->notes($id, 'OpportunityStageChanged'), 'a person\'s move leaves a pill too');
        self::assertSame('reverted', $this->service()->decide($id, $this->body('move', 'lead', 'proposal'))->outcome);
        self::assertSame($this->stages['lead']->getId(), $this->em->getEntityById('Opportunity', $id)->get('opportunityStageId'));
        // Another stage is still open to it.
        self::assertSame('moved', $this->service()->decide($id, $this->body('move', 'lead', 'won'))->outcome);
    }

    public function testAStageMissingItsRequiredFieldsBlocksTheMoveAndSaysSoOnce(): void
    {
        $this->create('CustomFieldDef', [
            'name' => 'convenio', 'label' => 'Convênio', 'entityType' => 'Opportunity', 'type' => 'varchar', 'valueKey' => 'convenio',
            'tenantId' => $this->tenant->getId(), 'teamsIds' => [$this->team->getId()], 'isActive' => true,
        ]);
        $this->stages['won']->set('requiredCustomFieldKeys', ['convenio']);
        $this->em->saveEntity($this->stages['won'], ['skipHooks' => true]);
        $deal = $this->deal('proposal');
        $id = $deal->getId();

        // Two looks at the same deal: neither moves it, and the stream is told once.
        foreach ([1, 2] as $look) {
            self::assertSame('blocked', $this->service()->decide($id, $this->body('move', 'proposal', 'won'))->outcome, "look $look");
        }
        $still = $this->em->getEntityById('Opportunity', $id);
        self::assertSame($this->stages['proposal']->getId(), $still->get('opportunityStageId'));
        self::assertSame('Open', $still->get('status'));
        self::assertSame([], $this->notes($id, 'OpportunityStageChanged'));
        self::assertSame(['blocked', 'blocked'], $this->decisions($id));

        $posts = array_values(array_filter($this->notes($id, 'Post'), fn ($note) => $note->get('data')->opportunityWatcher ?? null));
        self::assertCount(1, $posts);
        self::assertTrue($posts[0]->get('isInternal'));
        $marker = $posts[0]->get('data')->opportunityWatcher;
        self::assertSame('blocked', $marker->kind);
        self::assertSame('Ganho', $marker->toStageName);
        self::assertSame(['Convênio'], $marker->missingFields);

        // With the field filled in, the same move goes through.
        $still->set('customFields', (object) ['convenio' => 'Unimed']);
        $this->em->saveEntity($still);
        self::assertSame('moved', $this->service()->decide($id, $this->body('move', 'proposal', 'won'))->outcome);
        self::assertSame('Won', $this->em->getEntityById('Opportunity', $id)->get('status'));
    }

    public function testAnotherTenantsUserCannotDecide(): void
    {
        $deal = $this->deal('proposal');
        $team = $this->create('Team', ['name' => 'Other tenant']);
        $this->create('Tenant', ['name' => 'Other tenant', 'baseUserTeamId' => $team->getId()]);
        $outsider = $this->user($team);
        foreach (['move', 'none'] as $outcome) {
            try {
                $this->service($outsider)->decide($deal->getId(), $this->body($outcome, 'proposal', 'won'));
                self::fail('A user of another tenant decided on the deal.');
            } catch (Forbidden) {
            }
        }
        self::assertSame($this->stages['proposal']->getId(), $this->em->getEntityById('Opportunity', $deal->getId())->get('opportunityStageId'));
        self::assertSame([], $this->decisions($deal->getId()));
    }
}

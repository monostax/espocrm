<?php

declare(strict_types=1);

namespace tests\integration\Espo\Modules\Chatwoot;

use Espo\Core\Application;
use Espo\Core\Application\ApplicationParams;
use Espo\Core\Utils\Database\Schema\SchemaManager;
use Espo\Entities\User;
use Espo\Modules\Chatwoot\Services\OpportunityWatcher;
use Espo\Modules\FeatureCatchUp\Services\ChatwootAccess;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use PHPUnit\Framework\TestCase;

/** Uses a disposable installed database (data/config.php), real ACL, Opportunity and Note hooks, and transactions. */
abstract class OpportunityWatcherTestCase extends TestCase
{
    private static bool $schemaReady = false;
    protected Application $app;
    protected EntityManager $em;
    protected Entity $tenant;
    protected Entity $team;
    protected Entity $role;
    protected User $ai;
    protected Entity $funnel;
    /** @var array<string, Entity> */
    protected array $stages = [];

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
        $this->role = $this->create('Role', ['name' => 'Watcher test', 'assignmentPermission' => 'team', 'mentionPermission' => 'all', 'data' => (object) [
            'Opportunity' => (object) ['read' => 'all', 'stream' => 'all', 'create' => 'yes', 'edit' => 'all', 'delete' => 'no'],
            'OpportunityStage' => (object) ['read' => 'all'],
            'Funnel' => (object) ['read' => 'all'],
            'Note' => (object) ['read' => 'all', 'create' => 'yes', 'edit' => 'own', 'delete' => 'own'],
            'User' => (object) ['read' => 'all'],
            'Team' => (object) ['read' => 'all'],
            'Task' => (object) ['read' => 'team', 'stream' => 'team', 'create' => 'yes', 'edit' => 'team', 'delete' => 'own'],
            'ChatwootConversation' => (object) ['read' => 'all'],
            'ChatwootAccountUserMembership' => (object) ['read' => 'all'],
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

    protected function create(string $type, array $data): Entity
    {
        return $this->em->createEntity($type, $data, ['skipHooks' => true]);
    }

    protected function user(Entity $team): User
    {
        $user = $this->create('User', [
            'userName' => 'watcher-' . bin2hex(random_bytes(6)), 'type' => 'regular', 'isActive' => true,
        ]);
        $this->em->getRDBRepository('User')->getRelation($user, 'teams')->relate($team);
        $this->em->getRDBRepository('User')->getRelation($user, 'roles')->relate($this->role);
        return $this->em->getEntityById('User', $user->getId());
    }

    /** The service as the actor (the AI by default); `$access` stands in for Chatwoot's conversation access. */
    protected function service(?User $actor = null, ?ChatwootAccess $access = null): OpportunityWatcher
    {
        $actor = $this->em->getEntityById('User', ($actor ?? $this->ai)->getId());
        $factory = (new Application(new ApplicationParams(noErrorHandler: true, services: ['user' => $actor])))->getInjectableFactory();
        return $access
            ? $factory->createWith(OpportunityWatcher::class, ['chatwootAccess' => $access])
            : $factory->create(OpportunityWatcher::class);
    }

    protected function deal(string $stage = 'lead', array $extra = []): Entity
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

    protected function body(string $outcome, string $from, ?string $to, array $extra = []): object
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

    protected function notes(string $id, string $type): array
    {
        return iterator_to_array($this->em->getRDBRepository('Note')
            ->where(['parentType' => 'Opportunity', 'parentId' => $id, 'type' => $type])->order('number')->find());
    }

    protected function decisions(string $id): array
    {
        return array_map(fn ($row) => $row->get('outcome'), iterator_to_array(
            $this->em->getRDBRepository('OpportunityWatcherDecision')->where(['opportunityId' => $id])->order('createdAt')->find()));
    }
}

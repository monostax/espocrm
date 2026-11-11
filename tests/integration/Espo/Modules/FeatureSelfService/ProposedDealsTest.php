<?php

declare(strict_types=1);

namespace tests\integration\Espo\Modules\FeatureSelfService;

use DateTimeImmutable;
use DateTimeZone;
use Espo\Core\Application;
use Espo\Core\Application\ApplicationParams;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Conflict;
use Espo\Modules\FeatureCatchUp\Classes\DeckRequest;
use Espo\Modules\FeatureCatchUp\Services\ProposedDeals;
use Espo\Modules\FeatureCatchUp\Services\Revision;
use Espo\Modules\FeatureCatchUp\Services\ServiceFeed;
use Espo\Modules\FeatureCatchUp\Services\Verdicts;
use Espo\Modules\FeatureCredits\Enrichment\ActorScope;
use Espo\Modules\FeatureSelfService\Tools\Policy\SelfServicePolicy;
use Espo\Modules\FeatureSelfService\Tools\Provisioning\Provisioner;
use Espo\Modules\FeatureSelfService\Tools\Provisioning\ProvisioningRequest;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use PHPUnit\Framework\TestCase;

/**
 * "Criar oportunidade" on an Atendimento card against a real CRM database and a fake Chatwoot, as the owner of a
 * freshly provisioned workspace (its "Vendas" funnel, Em dia on). Same runtime as ProvisioningTest; see
 * FeatureSelfService/index.md.
 */
class ProposedDealsTest extends TestCase
{
    private const TIME_ZONE = 'America/Sao_Paulo';

    private Application $app;
    private EntityManager $em;
    private string $fake;

    protected function setUp(): void
    {
        $database = getenv('SELF_SERVICE_TEST_DATABASE');
        $this->fake = (string) getenv('FAKE_CHATWOOT_URL');

        if (!$database || $this->fake === '') {
            self::markTestSkipped('Set SELF_SERVICE_TEST_DATABASE and FAKE_CHATWOOT_URL in an isolated CRM runtime.');
        }

        $this->app = new Application(new ApplicationParams(noErrorHandler: true));
        self::assertSame($database, $this->app->getContainer()->get('config')->get('database.dbname'));
        $this->app->setupSystemUser();
        $this->em = $this->app->getContainer()->get('entityManager');

        $this->http('POST', '/__reset');
        putenv(SelfServicePolicy::ENV . '=true');
        $this->app->getInjectableFactory()->create(SelfServicePolicy::class)->resume();
    }

    public function testCreatesTheFoundDealAsTheCardShowsItInTheProposedFunnelAndStage(): void
    {
        $workspace = $this->workspace('Clínica Propostas');
        $won = $this->stage($workspace['funnel'], 100);
        $a = $this->conversation($workspace, 501, 'Ana');
        $this->propose($workspace, $a, ['name' => 'Ana - Limpeza de pele', 'amount' => 250, 'funnelId' => $workspace['funnel']->getId(),
            'stageId' => $won->getId()]);
        $token = $this->shown($workspace, $a);

        $result = $this->asOwner($workspace, fn (ProposedDeals $deals) => $deals->create($this->request($workspace, ['snapshot' => $token]), $a->getId()));

        $deal = $this->em->getEntityById('Opportunity', $result['id']);
        self::assertSame('Ana - Limpeza de pele', $deal->get('name'));
        self::assertEquals(250, $deal->get('amount'));
        self::assertSame([$workspace['funnel']->getId(), $won->getId()], [$deal->get('funnelId'), $deal->get('opportunityStageId')]);
        self::assertSame('Won', $deal->get('status'), 'Paid and booked: created won.');
        self::assertSame($workspace['tenant']->getId(), $deal->get('tenantId'));
        self::assertSame($workspace['ownerId'], $deal->get('assignedUserId'), 'Nobody assigned in Chatwoot: the reader.');
        self::assertSame($workspace['ownerId'], $deal->get('createdById'), 'Created as the owner, not the system.');
        self::assertSame([$a->getId()], $deal->getLinkMultipleIdList('chatwootConversations'));

        // The card is resolved: the same card can't create a second deal.
        try {
            $this->asOwner($workspace, fn (ProposedDeals $deals) => $deals->create($this->request($workspace, ['snapshot' => $token]), $a->getId()));
            self::fail('A resolved proposal created a second deal.');
        } catch (Conflict) {
            self::assertCount(1, $this->deals($a));
        }
    }

    public function testTheReaderCanChangeNameAmountFunnelAndStageBeforeCreating(): void
    {
        $workspace = $this->workspace('Clínica Funis');
        [$exams, $quote] = $this->funnel($workspace, 'Exames', 'Orçamento', 40);
        $b = $this->conversation($workspace, 502, 'Bruno');
        $this->propose($workspace, $b, ['name' => 'Bruno - Consulta', 'amount' => 800, 'funnelId' => $workspace['funnel']->getId(),
            'stageId' => $this->stage($workspace['funnel'], 100)->getId()]);
        $token = $this->shown($workspace, $b);

        // A stage of another funnel is refused.
        try {
            $this->asOwner($workspace, fn (ProposedDeals $deals) => $deals->create($this->request($workspace, [
                'snapshot' => $token, 'funnelId' => $exams->getId(), 'stageId' => $this->stage($workspace['funnel'], 100)->getId(),
            ]), $b->getId()));
            self::fail('A stage of another funnel was accepted.');
        } catch (BadRequest $e) {
            self::assertSame('invalidStage', $e->getMessage());
        }

        $result = $this->asOwner($workspace, fn (ProposedDeals $deals) => $deals->create($this->request($workspace, [
            'snapshot' => $token, 'name' => 'Bruno - Ultrassom', 'amount' => null, 'funnelId' => $exams->getId(), 'stageId' => $quote->getId(),
        ]), $b->getId()));
        $deal = $this->em->getEntityById('Opportunity', $result['id']);
        self::assertSame(['Bruno - Ultrassom', 0.0, $exams->getId(), $quote->getId(), 'Open'],
            [$deal->get('name'), (float) $deal->get('amount'), $deal->get('funnelId'), $deal->get('opportunityStageId'), $deal->get('status')]);
    }

    public function testAProposalFromBeforeFunnelsGoesInTheDefaultFunnelsFirstOpenStage(): void
    {
        $workspace = $this->workspace('Clínica Antiga');
        $c = $this->conversation($workspace, 503, 'Carla');
        $this->propose($workspace, $c, ['name' => 'Carla - Peeling', 'amount' => 400]);

        $result = $this->asOwner($workspace, fn (ProposedDeals $deals) => $deals->create(
            $this->request($workspace, ['snapshot' => $this->shown($workspace, $c)]), $c->getId()));
        $deal = $this->em->getEntityById('Opportunity', $result['id']);
        self::assertSame($workspace['funnel']->getId(), $deal->get('funnelId'));
        self::assertSame('Em negociação', $this->em->getEntityById('OpportunityStage', $deal->get('opportunityStageId'))->get('name'));
    }

    public function testAConversationThatMovedSinceTheCardWasShownIsAConflict(): void
    {
        $workspace = $this->workspace('Clínica Movida');
        $conversation = $this->conversation($workspace, 601, 'Elisa');
        $this->propose($workspace, $conversation, ['name' => 'Elisa - Botox', 'amount' => 900]);
        $token = $this->shown($workspace, $conversation);

        // A new message since the card was shown: the proposal belongs to an older revision.
        $conversation->set(['lastActivityAt' => gmdate('Y-m-d H:i:s', time() - 3600), 'lastMessageReceivedAt' => gmdate('Y-m-d H:i:s', time() - 3600)]);
        $this->em->saveEntity($conversation, ['skipHooks' => true]);

        try {
            $this->asOwner($workspace, fn (ProposedDeals $deals) => $deals->create($this->request($workspace, ['snapshot' => $token]), $conversation->getId()));
            self::fail('A moved conversation created a deal.');
        } catch (Conflict) {
            self::assertSame([], $this->deals($conversation));
        }
    }

    public function testTheDealGoesToThePersonAssignedInChatwoot(): void
    {
        $workspace = $this->workspace('Clínica Equipe');
        $suffix = bin2hex(random_bytes(4));
        $agent = $this->em->createEntity('User', [
            'userName' => 'agent-' . $suffix, 'type' => 'regular', 'isActive' => true, 'lastName' => 'Agent ' . $suffix,
            'emailAddress' => "agent-{$suffix}@example.com", 'teamsIds' => [(string) $workspace['tenant']->get('baseUserTeamId')],
        ]);
        $chatwootUserId = random_int(100000, 999999);
        $this->em->createEntity('ChatwootUser', [
            'name' => 'Agent ' . $suffix, 'chatwootUserId' => $chatwootUserId, 'platformId' => $workspace['account']->get('platformId'),
            'assignedUserId' => $agent->getId(),
        ], ['skipHooks' => true]);
        $conversation = $this->conversation($workspace, 701, 'Fábio');
        $conversation->set('assigneeId', $chatwootUserId);
        $this->em->saveEntity($conversation, ['skipHooks' => true]);
        $this->propose($workspace, $conversation, ['name' => 'Fábio - Ultrassom', 'amount' => 180]);

        $result = $this->asOwner($workspace, fn (ProposedDeals $deals) => $deals->create(
            $this->request($workspace, ['snapshot' => $this->shown($workspace, $conversation)]), $conversation->getId()));
        self::assertSame($agent->getId(), $this->em->getEntityById('Opportunity', $result['id'])->get('assignedUserId'));
    }

    /**
     * @return array{tenant: Entity, account: Entity, funnel: Entity, ownerId: string, remoteAccountId: int, inboxId: string}
     */
    private function workspace(string $name): array
    {
        $suffix = bin2hex(random_bytes(4));
        $owner = $this->em->createEntity('User', [
            'userName' => 'owner-' . $suffix,
            'type' => 'regular',
            'isActive' => true,
            'lastName' => 'Owner ' . $suffix,
            'emailAddress' => "owner-{$suffix}@example.com",
        ]);
        $provisioner = $this->app->getInjectableFactory()->create(Provisioner::class);
        $record = $provisioner->start(new ProvisioningRequest('signup:deals-' . $suffix, (string) $owner->getId(), $name));
        $done = $provisioner->run($record->getId());
        self::assertSame('completed', $done->get('status'), (string) $done->get('lastError'));
        $tenant = $this->em->getEntityById('Tenant', $done->get('tenantId'));
        $account = $this->em->getEntityById('ChatwootAccount', $done->get('chatwootAccountId'));
        $baseTeamId = (string) $tenant->get('baseUserTeamId');

        // The connected number, and the owner on it, as the membership sync leaves them in production.
        $inbox = $this->em->createEntity('ChatwootInbox', [
            'name' => 'WhatsApp', 'chatwootInboxId' => random_int(1000, 999999), 'chatwootAccountId' => $account->getId(),
            'channelType' => 'whatsappQrcode', 'teamsIds' => [$baseTeamId],
        ], ['skipHooks' => true]);
        $membership = $this->em->getRDBRepository('ChatwootAccountUserMembership')
            ->join('chatwootUser')
            ->where(['chatwootAccountId' => $account->getId(), 'chatwootUser.assignedUserId' => $owner->getId()])
            ->findOne();
        $this->em->getRDBRepository('ChatwootAccountUserMembership')->getRelation($membership, 'chatwootInboxes')->relateById($inbox->getId());

        return [
            'tenant' => $tenant,
            'account' => $account,
            'funnel' => $this->em->getRDBRepository('Funnel')->where(['tenantId' => $tenant->getId()])->findOne(),
            'ownerId' => (string) $owner->getId(),
            'remoteAccountId' => (int) $account->get('chatwootAccountId'),
            'inboxId' => (string) $inbox->getId(),
        ];
    }

    /** A settled conversation without a deal, its customer waiting: an Atendimento candidate. */
    private function conversation(array $workspace, int $displayId, string $contact): Entity
    {
        $at = gmdate('Y-m-d H:i:s', time() - 7200);

        $conversation = $this->em->createEntity('ChatwootConversation', [
            'name' => $contact,
            'contactDisplayName' => $contact,
            'chatwootConversationId' => $displayId,
            'chatwootAccountId' => $workspace['account']->getId(),
            'inboxId' => $workspace['inboxId'],
            'teamsIds' => [(string) $workspace['tenant']->get('baseUserTeamId')],
            'status' => 'open',
            'lastActivityAt' => $at,
            'lastMessageReceivedAt' => $at,
        ], ['skipHooks' => true]);
        // Without hooks the team isn't linked by the save; synced conversations always carry it.
        $this->em->getRDBRepository('ChatwootConversation')->getRelation($conversation, 'teams')
            ->relateById((string) $workspace['tenant']->get('baseUserTeamId'));

        return $this->em->getEntityById('ChatwootConversation', $conversation->getId());
    }

    /** @param array{name: string, amount: ?float, funnelId?: string, stageId?: string} $deal */
    private function propose(array $workspace, Entity $conversation, array $deal): void
    {
        $this->store($workspace, $conversation, 'new_deal', [
            'headline' => 'Pediu orçamento', 'whyNow' => 'Quer agendar esta semana', 'proposedDeal' => $deal,
        ]);
    }

    private function store(array $workspace, Entity $conversation, string $verdict, ?array $card): void
    {
        $now = new DateTimeImmutable('now', new DateTimeZone(self::TIME_ZONE));
        $this->app->getInjectableFactory()->create(Verdicts::class)->store(
            (string) $workspace['tenant']->getId(), 'ChatwootConversation', (string) $conversation->getId(),
            Revision::ofConversation($conversation, $now), 'pt-BR', 'scope-' . $conversation->getId(), $verdict, 'test', $card,
        );
    }

    /** The card as the owner's deck shows it (Atendimento snapshot): its token. */
    private function shown(array $workspace, Entity $conversation): string
    {
        $snapshot = $this->app->getInjectableFactory()->create(ActorScope::class)->run($workspace['ownerId'],
            fn (callable $make) => $make(ServiceFeed::class)->snapshot((string) $conversation->getId(), $this->request($workspace)));
        return (string) $snapshot['snapshot'];
    }

    /** A funnel's first active stage at this probability. */
    private function stage(Entity $funnel, int $probability): Entity
    {
        $stage = $this->em->getRDBRepository('OpportunityStage')
            ->where(['funnelId' => $funnel->getId(), 'probability' => $probability, 'isActive' => true])->order('order')->findOne();
        self::assertNotNull($stage, "The funnel has a {$probability}% stage.");
        return $stage;
    }

    /** @return array{Entity, Entity} a second funnel and its one open stage */
    private function funnel(array $workspace, string $name, string $stageName, int $probability): array
    {
        $team = (string) $workspace['tenant']->get('baseUserTeamId');
        $funnel = $this->em->createEntity('Funnel', [
            'name' => $name, 'tenantId' => $workspace['tenant']->getId(), 'teamsIds' => [$team], 'isActive' => true, 'isDefault' => false,
        ]);
        $stage = $this->em->createEntity('OpportunityStage', [
            'name' => $stageName, 'order' => 1, 'probability' => $probability, 'funnelId' => $funnel->getId(), 'teamsIds' => [$team],
        ]);
        return [$funnel, $stage];
    }

    /** @return list<string> the deals linked to a conversation */
    private function deals(Entity $conversation): array
    {
        return array_map(fn (Entity $deal) => (string) $deal->getId(), iterator_to_array($this->em->getRDBRepository('ChatwootConversation')
            ->getRelation($conversation, 'opportunities')->find(), false));
    }

    /**
     * @param callable(ProposedDeals): array $work
     */
    private function asOwner(array $workspace, callable $work): array
    {
        return $this->app->getInjectableFactory()->create(ActorScope::class)
            ->run($workspace['ownerId'], fn (callable $make) => $work($make(ProposedDeals::class)));
    }

    /** @param array<string, mixed> $body */
    private function request(array $workspace, array $body = []): DeckRequest
    {
        return new DeckRequest(['accountId' => (string) $workspace['remoteAccountId'], 'timeZone' => self::TIME_ZONE], $body);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<mixed>
     */
    private function http(string $method, string $path, array $body = []): array
    {
        $curl = curl_init(rtrim($this->fake, '/') . $path);
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode((object) $body),
        ]);
        $response = curl_exec($curl);
        curl_close($curl);

        return json_decode((string) $response, true) ?: [];
    }
}

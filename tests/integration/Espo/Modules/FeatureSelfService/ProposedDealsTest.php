<?php

declare(strict_types=1);

namespace tests\integration\Espo\Modules\FeatureSelfService;

use DateTimeImmutable;
use DateTimeZone;
use Espo\Core\Application;
use Espo\Core\Application\ApplicationParams;
use Espo\Core\Exceptions\BadRequest;
use Espo\Modules\FeatureCatchUp\Classes\DeckRequest;
use Espo\Modules\FeatureCatchUp\Services\ProposedDeals;
use Espo\Modules\FeatureCatchUp\Services\Revision;
use Espo\Modules\FeatureCatchUp\Services\Verdicts;
use Espo\Modules\FeatureCredits\Enrichment\ActorScope;
use Espo\Modules\FeatureSelfService\Tools\Policy\SelfServicePolicy;
use Espo\Modules\FeatureSelfService\Tools\Provisioning\Provisioner;
use Espo\Modules\FeatureSelfService\Tools\Provisioning\ProvisioningRequest;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use PHPUnit\Framework\TestCase;

/**
 * "Criar todas" against a real CRM database and a fake Chatwoot, as the owner of a freshly provisioned workspace
 * (its "Vendas" funnel, Em dia on). Same runtime as ProvisioningTest; see FeatureSelfService/index.md.
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

    public function testListsTheReadersProposalsAndCreatesTheChosenOnesInTheDefaultFunnel(): void
    {
        $workspace = $this->workspace('Clínica Propostas');
        $a = $this->conversation($workspace, 501, 'Ana');
        $b = $this->conversation($workspace, 502, 'Bruno');
        $hidden = $this->conversation($workspace, 503, 'Carla');
        $none = $this->conversation($workspace, 504, 'Davi');
        $this->propose($workspace, $a, ['name' => 'Ana - Limpeza de pele', 'amount' => 250]);
        $this->propose($workspace, $b, ['name' => 'Bruno - Massagem', 'amount' => null]);
        $this->propose($workspace, $hidden, ['name' => 'Carla - Peeling', 'amount' => 400]);
        $this->store($workspace, $none, 'none', null);
        // Chatwoot won't let the owner open Carla's conversation: her proposal is not offered.
        $this->http('POST', '/__seed', ['accountId' => $workspace['remoteAccountId'], 'deniedConversations' => [503]]);

        $listed = $this->asOwner($workspace, fn (ProposedDeals $deals) => $deals->list($this->request($workspace)));
        self::assertSame(['Ana - Limpeza de pele', 'Bruno - Massagem'], array_column($listed['items'], 'name'));
        self::assertSame([250.0, null], array_column($listed['items'], 'amount'));
        self::assertSame('Ana', $listed['items'][0]['contactName']);
        self::assertSame([['id' => $workspace['funnel']->getId(), 'name' => 'Vendas', 'isDefault' => true]], $listed['funnels']);

        $result = $this->asOwner($workspace, fn (ProposedDeals $deals) => $deals->create($this->request($workspace, [
            'items' => [
                (object) ['conversationId' => $a->getId(), 'name' => 'Ana - Limpeza de pele premium', 'amount' => 300],
                (object) ['conversationId' => $b->getId()],
                (object) ['conversationId' => $hidden->getId()],
                (object) ['conversationId' => $none->getId()],
            ],
        ])));

        self::assertSame([$a->getId(), $b->getId()], array_column($result['created'], 'conversationId'));
        self::assertSame([$hidden->getId(), $none->getId()], $result['skipped'], 'Only proposals the owner could act on are created.');
        self::assertSame([], $result['failed']);

        $deal = $this->em->getEntityById('Opportunity', $result['created'][0]['opportunityId']);
        self::assertSame('Ana - Limpeza de pele premium', $deal->get('name'));
        self::assertEquals(300, $deal->get('amount'));
        self::assertSame($workspace['funnel']->getId(), $deal->get('funnelId'));
        self::assertSame('Em negociação', $this->em->getEntityById('OpportunityStage', $deal->get('opportunityStageId'))->get('name'));
        self::assertSame('Open', $deal->get('status'));
        self::assertSame($workspace['tenant']->getId(), $deal->get('tenantId'));
        self::assertSame($workspace['ownerId'], $deal->get('assignedUserId'));
        self::assertSame($workspace['ownerId'], $deal->get('createdById'), 'Created as the owner, not the system.');
        self::assertSame([$a->getId()], $deal->getLinkMultipleIdList('chatwootConversations'));
        self::assertSame('Bruno - Massagem', $this->em->getEntityById('Opportunity', $result['created'][1]['opportunityId'])->get('name'));

        // Their cards are resolved and they now have a deal: nothing is offered twice.
        $again = $this->asOwner($workspace, fn (ProposedDeals $deals) => $deals->list($this->request($workspace)));
        self::assertSame([], $again['items']);
        $twice = $this->asOwner($workspace, fn (ProposedDeals $deals) => $deals->create($this->request($workspace, [
            'items' => [(object) ['conversationId' => $a->getId()]],
        ])));
        self::assertSame([], $twice['created']);
    }

    public function testAProposalTheConversationHasOvertakenIsNotOffered(): void
    {
        $workspace = $this->workspace('Clínica Movida');
        $conversation = $this->conversation($workspace, 601, 'Elisa');
        $this->propose($workspace, $conversation, ['name' => 'Elisa - Botox', 'amount' => 900]);

        // A new message since the analysis: the proposal belongs to an older revision.
        $conversation->set(['lastActivityAt' => gmdate('Y-m-d H:i:s', time() - 3600), 'lastMessageReceivedAt' => gmdate('Y-m-d H:i:s', time() - 3600)]);
        $this->em->saveEntity($conversation, ['skipHooks' => true]);

        $listed = $this->asOwner($workspace, fn (ProposedDeals $deals) => $deals->list($this->request($workspace)));
        self::assertSame([], $listed['items']);
    }

    public function testSeveralFunnelsWithoutADefaultNeedAChoice(): void
    {
        $workspace = $this->workspace('Clínica Funis');
        $workspace['funnel']->set('isDefault', false);
        $this->em->saveEntity($workspace['funnel']);
        $exams = $this->em->createEntity('Funnel', [
            'name' => 'Exames', 'tenantId' => $workspace['tenant']->getId(),
            'teamsIds' => [(string) $workspace['tenant']->get('baseUserTeamId')], 'isActive' => true, 'isDefault' => false,
        ]);
        $stage = $this->em->createEntity('OpportunityStage', [
            'name' => 'Orçamento', 'order' => 1, 'probability' => 40, 'funnelId' => $exams->getId(),
            'teamsIds' => [(string) $workspace['tenant']->get('baseUserTeamId')],
        ]);
        $conversation = $this->conversation($workspace, 701, 'Fábio');
        $this->propose($workspace, $conversation, ['name' => 'Fábio - Ultrassom', 'amount' => 180]);
        $items = [(object) ['conversationId' => $conversation->getId()]];

        try {
            $this->asOwner($workspace, fn (ProposedDeals $deals) => $deals->create($this->request($workspace, ['items' => $items])));
            self::fail('Without a default funnel the reader must choose one.');
        } catch (BadRequest $e) {
            self::assertSame('funnelRequired', $e->getMessage());
        }

        $result = $this->asOwner($workspace, fn (ProposedDeals $deals) => $deals->create(
            $this->request($workspace, ['items' => $items, 'funnelId' => $exams->getId()])));
        $deal = $this->em->getEntityById('Opportunity', $result['created'][0]['opportunityId']);
        self::assertSame($exams->getId(), $deal->get('funnelId'));
        self::assertSame($stage->getId(), $deal->get('opportunityStageId'));
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

    /** @param array{name: string, amount: ?float} $deal */
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

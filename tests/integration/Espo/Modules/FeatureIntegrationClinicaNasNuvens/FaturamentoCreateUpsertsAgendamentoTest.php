<?php

namespace tests\integration\Espo\Modules\FeatureIntegrationClinicaNasNuvens;

use Espo\Core\Binding\Binder;
use Espo\Core\Binding\BindingProcessor;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Record\CreateParams;
use Espo\Core\Record\DeleteParams;
use Espo\Core\Record\Service;
use Espo\Core\Record\ServiceContainer;
use Espo\Modules\FeatureIntegrationClinicaNasNuvens\Services\ClinicaNasNuvensApiClient;
use Espo\Modules\FeatureIntegrationClinicaNasNuvens\Services\ClinicaNasNuvensWebClient;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use tests\integration\Core\BaseTestCase;

class FaturamentoCreateUpsertsAgendamentoTest extends BaseTestCase
{
    public function testCreateRejectsInvalidFaturamentoIdBeforeSave(): void
    {
        $team = $this->createTeam('cnn-fat-invalid');
        $this->createWebCredentialForTeam($team);
        $this->useMockClients([], []);

        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage("Cannot create faturamento anchor 'fat-invalid'");

        try {
            $this->createFaturamento([
                'faturamentoId' => 'fat-invalid',
                'teamsIds' => [$team->getId()],
            ]);
        } finally {
            $created = $this->getEntityManagerInstance()
                ->getRDBRepository('FeatureIntegrationClinicaNasNuvensFaturamento')
                ->where([
                    'faturamentoId' => 'fat-invalid',
                    'deleted' => false,
                ])
                ->findOne();

            $this->assertNull($created);
        }
    }

    public function testCreateReusesExistingActiveAgendamentoAndMergesTeams(): void
    {
        $teamA = $this->createTeam('cnn-fat-a');
        $teamB = $this->createTeam('cnn-fat-b');
        $apiCredential = $this->createApiCredentialForTeam($teamA);
        $webCredential = $this->createWebCredentialForTeam($teamA);

        $this->useMockClients([
            'fat-1' => [
                'documento' => 'DOC-1',
                'dataFaturamento' => '2026-03-25',
                'profissionalNome' => 'Dr. João',
                'conta' => 'Conta A',
                'valor' => 146.00,
                'valorCurrency' => 'BRL',
                'parcela' => '1/3',
                'dataVencimento' => '2026-04-05',
                'description' => 'Desc',
                'agendamentoId' => 'ag-remoto-1',
            ],
        ], [
            'ag-remoto-1' => [
                'agendamentoId' => 'ag-remoto-1',
                'name' => 'Agendamento #ag-remoto-1',
                'idPaciente' => null,
                'idProfissional' => 'remote-prof-1',
                'idPessoaExecutor' => 'remote-pessoa-prof-1',
            ],
        ]);

        $profissional = $this->getEntityManager()->createEntity('FeatureIntegrationClinicaNasNuvensProfissional', [
            'name' => 'Profissional Existing',
            'profissionalId' => 'remote-prof-1',
            'idPessoa' => 'remote-pessoa-prof-1',
            'credentialId' => $apiCredential->getId(),
            'teamsIds' => [$teamA->getId()],
        ]);

        $agendamento = $this->getEntityManager()->createEntity('FeatureIntegrationClinicaNasNuvensAgendamento', [
            'agendamentoId' => 'ag-remoto-1',
            'credentialId' => $apiCredential->getId(),
            'teamsIds' => [$teamA->getId()],
        ]);

        $faturamento = $this->createFaturamento([
            'faturamentoId' => 'fat-1',
            'teamsIds' => [$teamB->getId(), $teamA->getId()],
        ]);

        $reloadedFaturamento = $this->getEntityManager()->getEntityById(
            'FeatureIntegrationClinicaNasNuvensFaturamento',
            $faturamento->getId(),
        );

        $this->assertNotNull($reloadedFaturamento);
        $this->assertSame($webCredential->getId(), $reloadedFaturamento->get('credentialId'));
        $this->assertSame($agendamento->getId(), $reloadedFaturamento->get('agendamentoId'));
        $this->assertSame($profissional->getId(), $reloadedFaturamento->get('profissionalAnchorId'));
        $this->assertSame(146.00, (float) $reloadedFaturamento->get('valor'));
        $this->assertSame('BRL', $reloadedFaturamento->get('valorCurrency'));
        $this->assertSame('2026-03-25', $reloadedFaturamento->get('dataFaturamento'));
        $this->assertSame('2026-04-05', $reloadedFaturamento->get('dataVencimento'));

        $reloadedAgendamento = $this->getEntityManager()->getEntityById(
            'FeatureIntegrationClinicaNasNuvensAgendamento',
            $agendamento->getId(),
        );

        $this->assertNotNull($reloadedAgendamento);
        $this->assertEqualsCanonicalizing([$teamA->getId(), $teamB->getId()], $reloadedAgendamento->get('teamsIds'));
        $this->assertSame(146.00, (float) $reloadedAgendamento->get('valor'));
        $this->assertSame('BRL', $reloadedAgendamento->get('valorCurrency'));
    }

    public function testCreateRestoresSoftDeletedAgendamento(): void
    {
        $team = $this->createTeam('cnn-fat-restore');
        $apiCredential = $this->createApiCredentialForTeam($team);
        $this->createWebCredentialForTeam($team);

        $this->useMockClients([
            'fat-restore' => [
                'agendamentoId' => 'ag-restore',
                'valor' => 1.00,
                'valorCurrency' => 'BRL',
            ],
        ], [
            'ag-restore' => ['agendamentoId' => 'ag-restore', 'name' => 'Ag 1'],
        ]);

        $agendamento = $this->getEntityManager()->createEntity('FeatureIntegrationClinicaNasNuvensAgendamento', [
            'agendamentoId' => 'ag-restore',
            'credentialId' => $apiCredential->getId(),
            'teamsIds' => [$team->getId()],
        ]);

        $this->getAgendamentoService()->delete($agendamento->getId(), DeleteParams::create());

        $this->createFaturamento([
            'faturamentoId' => 'fat-restore',
            'teamsIds' => [$team->getId()],
        ]);

        $reloadedAgendamento = $this->getEntityManager()->getEntityById(
            'FeatureIntegrationClinicaNasNuvensAgendamento',
            $agendamento->getId(),
        );

        $this->assertNotNull($reloadedAgendamento);
        $this->assertFalse((bool) $reloadedAgendamento->get('deleted'));
    }

    public function testCreateCreatesMissingAgendamentoAnchor(): void
    {
        $team = $this->createTeam('cnn-fat-create-anchor');
        $apiCredential = $this->createApiCredentialForTeam($team);
        $this->createWebCredentialForTeam($team);

        $this->useMockClients([
            'fat-create' => [
                'agendamentoId' => 'ag-new-anchor',
                'valor' => 2.00,
                'valorCurrency' => 'BRL',
            ],
        ], [
            'ag-new-anchor' => ['agendamentoId' => 'ag-new-anchor', 'name' => 'Ag novo'],
        ]);

        $faturamento = $this->createFaturamento([
            'faturamentoId' => 'fat-create',
            'teamsIds' => [$team->getId()],
        ]);

        $reloadedFaturamento = $this->getEntityManager()->getEntityById(
            'FeatureIntegrationClinicaNasNuvensFaturamento',
            $faturamento->getId(),
        );

        $this->assertNotNull($reloadedFaturamento);

        $agendamento = $this->findAgendamentoAnchorIncludingDeleted('ag-new-anchor', $apiCredential->getId());

        $this->assertNotNull($agendamento);
        $this->assertSame($agendamento->getId(), $reloadedFaturamento->get('agendamentoId'));
    }

    public function testCreateWithoutWebCredentialRejectsCreateBeforeSave(): void
    {
        $team = $this->createTeam('cnn-fat-no-web');
        $faturamentoId = 'fat-no-web';

        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage("Cannot create faturamento anchor '{$faturamentoId}'");

        try {
            $this->createFaturamento([
                'faturamentoId' => $faturamentoId,
                'teamsIds' => [$team->getId()],
            ]);
        } finally {
            $created = $this->getEntityManagerInstance()
                ->getRDBRepository('FeatureIntegrationClinicaNasNuvensFaturamento')
                ->where([
                    'faturamentoId' => $faturamentoId,
                    'deleted' => false,
                ])
                ->findOne();

            $this->assertNull($created);
        }
    }

    public function testCreateWithWebCredentialAndMissingApiCredentialLinksAgendamentoInErrorState(): void
    {
        $team = $this->createTeam('cnn-fat-cross-credential');
        $webCredential = $this->createWebCredentialForTeam($team);

        $this->useMockClients([
            'fat-cross' => [
                'agendamentoId' => 'ag-cross',
                'valor' => 3.00,
                'valorCurrency' => 'BRL',
            ],
        ], []);

        $faturamento = $this->createFaturamento([
            'faturamentoId' => 'fat-cross',
            'teamsIds' => [$team->getId()],
        ]);

        $reloadedFaturamento = $this->getEntityManager()->getEntityById(
            'FeatureIntegrationClinicaNasNuvensFaturamento',
            $faturamento->getId(),
        );

        $this->assertNotNull($reloadedFaturamento);
        $this->assertSame('synced', $reloadedFaturamento->get('syncStatus'));
        $this->assertSame($webCredential->getId(), $reloadedFaturamento->get('credentialId'));
        $this->assertNotNull($reloadedFaturamento->get('agendamentoId'));

        $agendamento = $this->getEntityManager()->getEntityById(
            'FeatureIntegrationClinicaNasNuvensAgendamento',
            $reloadedFaturamento->get('agendamentoId'),
        );

        $this->assertNotNull($agendamento);
        $this->assertSame('error', $agendamento->get('syncStatus'));
        $this->assertNull($agendamento->get('credentialId'));
    }

    /**
     * @param array<string, array<string, mixed>> $faturamentoPayloadMap
     * @param array<string, array<string, mixed>> $agendaPayloadMap
     */
    private function useMockClients(array $faturamentoPayloadMap, array $agendaPayloadMap): void
    {
        $webClient = new FakeClinicaNasNuvensWebClient($faturamentoPayloadMap);
        $apiClient = new FakeClinicaNasNuvensApiClient($agendaPayloadMap);

        $app = $this->createApplication(
            binding: new class ($webClient, $apiClient) implements BindingProcessor {
                public function __construct(
                    private FakeClinicaNasNuvensWebClient $webClient,
                    private FakeClinicaNasNuvensApiClient $apiClient,
                ) {}

                public function process(Binder $binder): void
                {
                    $binder->bindInstance(ClinicaNasNuvensWebClient::class, $this->webClient);
                    $binder->bindInstance(ClinicaNasNuvensApiClient::class, $this->apiClient);
                }
            },
        );

        $this->setApplication($app);
    }

    private function getEntityManagerInstance(): EntityManager
    {
        return $this->getEntityManager();
    }

    private function getFaturamentoService(): Service
    {
        return $this->getContainer()
            ->getByClass(ServiceContainer::class)
            ->get('FeatureIntegrationClinicaNasNuvensFaturamento');
    }

    private function getAgendamentoService(): Service
    {
        return $this->getContainer()
            ->getByClass(ServiceContainer::class)
            ->get('FeatureIntegrationClinicaNasNuvensAgendamento');
    }

    /**
     * @param array<string, mixed> $data
     */
    private function createFaturamento(array $data): Entity
    {
        return $this->getFaturamentoService()->create((object) $data, CreateParams::create());
    }

    private function createTeam(string $name): Entity
    {
        return $this->getEntityManagerInstance()->createEntity('Team', ['name' => $name]);
    }

    private function createApiCredentialForTeam(Entity $team): Entity
    {
        $credentialType = $this->findOrCreateCredentialType('cnn', 'basicAuth');

        return $this->getEntityManagerInstance()->createEntity('Credential', [
            'name' => 'CNN API Credential ' . uniqid(),
            'credentialTypeId' => $credentialType->getId(),
            'isActive' => true,
            'config' => '{}',
            'teamsIds' => [$team->getId()],
        ]);
    }

    private function createWebCredentialForTeam(Entity $team): Entity
    {
        $credentialType = $this->findOrCreateCredentialType('clinicaNasNuvens-web', 'formAuth');

        return $this->getEntityManagerInstance()->createEntity('Credential', [
            'name' => 'CNN WEB Credential ' . uniqid(),
            'credentialTypeId' => $credentialType->getId(),
            'isActive' => true,
            'config' => '{"sessionCookies":"session=test"}',
            'teamsIds' => [$team->getId()],
        ]);
    }

    private function findOrCreateCredentialType(string $code, string $category): Entity
    {
        $repository = $this->getEntityManagerInstance()->getRDBRepository('CredentialType');

        $credentialType = $repository
            ->where(['code' => $code])
            ->findOne();

        if ($credentialType) {
            return $credentialType;
        }

        return $this->getEntityManagerInstance()->createEntity('CredentialType', [
            'name' => strtoupper($code) . ' Test Type',
            'code' => $code,
            'category' => $category,
            'schema' => '{}',
            'encryptionFields' => [],
        ]);
    }

    private function findAgendamentoAnchorIncludingDeleted(string $remoteAgendamentoId, string $credentialId): ?Entity
    {
        $query = $this->getEntityManagerInstance()
            ->getQueryBuilder()
            ->select()
            ->from('FeatureIntegrationClinicaNasNuvensAgendamento')
            ->where([
                'agendamentoId' => $remoteAgendamentoId,
                'credentialId' => $credentialId,
            ])
            ->withDeleted()
            ->build();

        return $this->getEntityManagerInstance()
            ->getRDBRepository('FeatureIntegrationClinicaNasNuvensAgendamento')
            ->clone($query)
            ->findOne();
    }
}

class FakeClinicaNasNuvensWebClient extends ClinicaNasNuvensWebClient
{
    /** @param array<string, array<string, mixed>> $payloadMap */
    public function __construct(private array $payloadMap)
    {
    }

    public function getDetalhesConta(Entity $credential, string $faturamentoId): array
    {
        if (!array_key_exists($faturamentoId, $this->payloadMap)) {
            throw new \RuntimeException("Missing fake faturamento payload for '{$faturamentoId}'.");
        }

        return array_merge(['faturamentoId' => $faturamentoId], $this->payloadMap[$faturamentoId]);
    }
}

class FakeClinicaNasNuvensApiClient extends ClinicaNasNuvensApiClient
{
    /** @param array<string, array<string, mixed>> $payloadMap */
    public function __construct(private array $payloadMap)
    {
    }

    public function getAgendaById(Entity $credential, string $agendamentoId): array
    {
        if (!array_key_exists($agendamentoId, $this->payloadMap)) {
            throw new \RuntimeException("Missing fake agenda payload for '{$agendamentoId}'.");
        }

        return $this->payloadMap[$agendamentoId];
    }
}

<?php

namespace tests\integration\Espo\Modules\FeatureIntegrationClinicaNasNuvens;

use Espo\Core\Binding\Binder;
use Espo\Core\Binding\BindingProcessor;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Record\CreateParams;
use Espo\Core\Record\Service;
use Espo\Core\Record\ServiceContainer;
use Espo\Modules\FeatureIntegrationClinicaNasNuvens\Services\ClinicaNasNuvensApiClient;
use Espo\Modules\FeatureIntegrationClinicaNasNuvens\Services\ClinicaNasNuvensWebClient;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use tests\integration\Core\BaseTestCase;

class AgendamentoCreateAutoCreatesFaturamentoTest extends BaseTestCase
{
    public function testCreateRejectsInvalidAgendamentoIdBeforeSave(): void
    {
        $team = $this->createTeam('cnn-invalid-agendamento-team');
        $this->createApiCredentialForTeam($team);

        $this->useMockClients([], [], []);

        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage("Cannot create agendamento anchor 'ag-invalid'");

        try {
            $this->createAgendamento([
                'agendamentoId' => 'ag-invalid',
                'teamsIds' => [$team->getId()],
            ]);
        } finally {
            $created = $this->getEntityManagerInstance()
                ->getRDBRepository('FeatureIntegrationClinicaNasNuvensAgendamento')
                ->where([
                    'agendamentoId' => 'ag-invalid',
                    'deleted' => false,
                ])
                ->findOne();

            $this->assertNull($created);
        }
    }

    public function testCreateAutoCreatesFaturamentosFromResumoAgendaFinanceiro(): void
    {
        $team = $this->createTeam('cnn-auto-fat-team');
        $this->createApiCredentialForTeam($team);
        $webCredential = $this->createWebCredentialForTeam($team);

        $this->useMockClients(
            [
                'ag-auto-fat' => ['fat-auto-1', 'fat-auto-2'],
            ],
            [
                'fat-auto-1' => [
                    'agendamentoId' => 'ag-auto-fat',
                    'valor' => 120.50,
                    'valorCurrency' => 'BRL',
                    'dataFaturamento' => '2026-03-25',
                ],
                'fat-auto-2' => [
                    'agendamentoId' => 'ag-auto-fat',
                    'valor' => 89.90,
                    'valorCurrency' => 'BRL',
                    'dataFaturamento' => '2026-03-25',
                ],
            ],
            [
                'ag-auto-fat' => [
                    'agendamentoId' => 'ag-auto-fat',
                    'idPaciente' => 'paciente-auto-fat',
                    'data' => '2026-03-25',
                    'horaInicio' => '2026-03-25 09:00:00',
                    'horaFim' => '2026-03-25 09:30:00',
                    'status' => 'Confirmado',
                ],
            ],
        );

        $agendamento = $this->createAgendamento([
            'agendamentoId' => 'ag-auto-fat',
            'idPaciente' => 'paciente-auto-fat',
            'teamsIds' => [$team->getId()],
        ]);

        $faturamentos = $this->getEntityManagerInstance()
            ->getRDBRepository('FeatureIntegrationClinicaNasNuvensFaturamento')
            ->where([
                'credentialId' => $webCredential->getId(),
                'deleted' => false,
            ])
            ->find();

        $count = 0;
        $faturamentoIdList = [];

        foreach ($faturamentos as $faturamento) {
            $count++;
            $faturamentoIdList[] = $faturamento->get('faturamentoId');
            $this->assertEqualsCanonicalizing([$team->getId()], $faturamento->get('teamsIds'));
            $this->assertSame($agendamento->getId(), $faturamento->get('agendamentoId'));
        }

        $this->assertSame(2, $count);
        $this->assertEqualsCanonicalizing(['fat-auto-1', 'fat-auto-2'], $faturamentoIdList);

        $reloadedAgendamento = $this->getEntityManagerInstance()->getEntityById(
            'FeatureIntegrationClinicaNasNuvensAgendamento',
            $agendamento->getId(),
        );
        $this->assertSame('FATURADO', $reloadedAgendamento?->get('statusFaturamento'));
    }

    /**
     * @param array<string, string[]> $agendamentoToFaturamentoMap
     * @param array<string, array<string, mixed>> $faturamentoPayloadMap
     * @param array<string, array<string, mixed>> $agendaPayloadMap
     */
    private function useMockClients(
        array $agendamentoToFaturamentoMap,
        array $faturamentoPayloadMap,
        array $agendaPayloadMap,
    ): void {
        $webClient = new FakeAutoCreateClinicaNasNuvensWebClient($agendamentoToFaturamentoMap, $faturamentoPayloadMap);
        $apiClient = new FakeAutoCreateClinicaNasNuvensApiClient($agendaPayloadMap);

        $app = $this->createApplication(
            binding: new class ($webClient, $apiClient) implements BindingProcessor {
                public function __construct(
                    private FakeAutoCreateClinicaNasNuvensWebClient $webClient,
                    private FakeAutoCreateClinicaNasNuvensApiClient $apiClient,
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

    private function getAgendamentoService(): Service
    {
        return $this->getContainer()
            ->getByClass(ServiceContainer::class)
            ->get('FeatureIntegrationClinicaNasNuvensAgendamento');
    }

    /**
     * @param array<string, mixed> $data
     */
    private function createAgendamento(array $data): Entity
    {
        return $this->getAgendamentoService()->create((object) $data, CreateParams::create());
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
}

class FakeAutoCreateClinicaNasNuvensWebClient extends ClinicaNasNuvensWebClient
{
    /**
     * @param array<string, string[]> $agendamentoToFaturamentoMap
     * @param array<string, array<string, mixed>> $faturamentoPayloadMap
     */
    public function __construct(
        private array $agendamentoToFaturamentoMap,
        private array $faturamentoPayloadMap,
    ) {
    }

    public function getFaturamentoIdsByAgendamentoId(Entity $credential, string $agendamentoId): array
    {
        return $this->agendamentoToFaturamentoMap[$agendamentoId] ?? [];
    }

    public function getDetalhesConta(Entity $credential, string $faturamentoId): array
    {
        if (!array_key_exists($faturamentoId, $this->faturamentoPayloadMap)) {
            throw new \RuntimeException("Missing fake faturamento payload for '{$faturamentoId}'.");
        }

        return array_merge(['faturamentoId' => $faturamentoId], $this->faturamentoPayloadMap[$faturamentoId]);
    }

    public function getStatusFaturamentoByAgendamentoId(Entity $credential, string $agendamentoId): ?string
    {
        return ($this->agendamentoToFaturamentoMap[$agendamentoId] ?? []) !== []
            ? 'FATURADO'
            : null;
    }
}

class FakeAutoCreateClinicaNasNuvensApiClient extends ClinicaNasNuvensApiClient
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

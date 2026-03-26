<?php

namespace tests\integration\Espo\Modules\FeatureIntegrationClinicaNasNuvens;

use Espo\Core\Binding\Binder;
use Espo\Core\Binding\BindingProcessor;
use Espo\Core\Record\CreateParams;
use Espo\Core\Record\ReadParams;
use Espo\Core\Record\Service;
use Espo\Core\Record\ServiceContainer;
use Espo\Modules\FeatureIntegrationClinicaNasNuvens\Services\ClinicaNasNuvensApiClient;
use Espo\Modules\FeatureIntegrationClinicaNasNuvens\Services\ClinicaNasNuvensWebClient;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use tests\integration\Core\BaseTestCase;

class ProcedimentoTipoConvenioSyncTest extends BaseTestCase
{
    public function testPricingSnapshotSyncUpsertsSoftDeletesAndRestoresRows(): void
    {
        $team = $this->createTeam('cnn-proc-convenio-sync');
        $this->createApiCredentialForTeam($team);
        $this->createWebCredentialForTeam($team);

        $this->useMockClients(
            [
                'proc-1' => [
                    'procedimentoTipoId' => 'proc-1',
                    'name' => 'Procedimento 1',
                    'ativo' => true,
                    'especialidades' => ['Clinica Geral'],
                ],
            ],
            [
                'conv-1' => [
                    'convenioTipoId' => 'conv-1',
                    'name' => 'Convênio A',
                    'ativo' => true,
                ],
                'conv-2' => [
                    'convenioTipoId' => 'conv-2',
                    'name' => 'Convênio B',
                    'ativo' => true,
                ],
            ],
            [
                'proc-1' => [
                    [
                        [
                            'codigoTipoProcedimentoConvenio' => 'proc-conv-1',
                            'codigoTipoConvenio' => 'conv-1',
                            'isActive' => true,
                            'precoPaciente' => 150.50,
                            'precoConvenio' => 110.00,
                        ],
                        [
                            'codigoTipoProcedimentoConvenio' => null,
                            'codigoTipoConvenio' => 'conv-2',
                            'isActive' => false,
                            'precoPaciente' => 0.00,
                            'precoConvenio' => 0.00,
                        ],
                    ],
                    [
                        [
                            'codigoTipoProcedimentoConvenio' => 'proc-conv-2b',
                            'codigoTipoConvenio' => 'conv-2',
                            'isActive' => true,
                            'precoPaciente' => null,
                            'precoConvenio' => 70.00,
                        ],
                    ],
                    [
                        [
                            'codigoTipoProcedimentoConvenio' => 'proc-conv-1b',
                            'codigoTipoConvenio' => 'conv-1',
                            'isActive' => true,
                            'precoPaciente' => 180.00,
                            'precoConvenio' => 120.00,
                        ],
                        [
                            'codigoTipoProcedimentoConvenio' => 'proc-conv-2c',
                            'codigoTipoConvenio' => 'conv-2',
                            'isActive' => true,
                            'precoPaciente' => 10.00,
                            'precoConvenio' => 70.00,
                        ],
                    ],
                ],
            ],
        );

        $procedimentoTipo = $this->createProcedimentoTipo([
            'procedimentoTipoId' => 'proc-1',
            'teamsIds' => [$team->getId()],
        ]);

        $reloaded = $this->getEntityManager()->getEntityById('FeatureIntegrationClinicaNasNuvensProcedimentoTipo', $procedimentoTipo->getId());
        $this->assertNotNull($reloaded);
        $this->assertSame('synced', $reloaded->get('syncStatus'));

        $rowConv1 = $this->findPricingRowIncludingDeletedByConvenioRemoteId($procedimentoTipo->getId(), 'conv-1');
        $rowConv2 = $this->findPricingRowIncludingDeletedByConvenioRemoteId($procedimentoTipo->getId(), 'conv-2');

        $this->assertNotNull($rowConv1);
        $this->assertNotNull($rowConv2);
        $this->assertFalse((bool) $rowConv1->get('deleted'));
        $this->assertFalse((bool) $rowConv2->get('deleted'));
        $this->assertSame('Convênio A', $rowConv1->get('convenioName'));
        $this->assertSame(0.00, $rowConv2->get('precoPaciente'));
        $this->assertSame('BRL', $rowConv2->get('precoPacienteCurrency'));
        $this->assertSame('BRL', $rowConv2->get('precoConvenioCurrency'));

        $this->getProcedimentoTipoService()->read($procedimentoTipo->getId(), ReadParams::create());

        $rowConv1AfterSecondSync = $this->findPricingRowIncludingDeletedByConvenioRemoteId($procedimentoTipo->getId(), 'conv-1');
        $rowConv2AfterSecondSync = $this->findPricingRowIncludingDeletedByConvenioRemoteId($procedimentoTipo->getId(), 'conv-2');

        $this->assertNotNull($rowConv1AfterSecondSync);
        $this->assertNotNull($rowConv2AfterSecondSync);
        $this->assertTrue((bool) $rowConv1AfterSecondSync->get('deleted'));
        $this->assertFalse((bool) $rowConv2AfterSecondSync->get('deleted'));
        $this->assertNull($rowConv2AfterSecondSync->get('precoPaciente'));

        $this->getProcedimentoTipoService()->read($procedimentoTipo->getId(), ReadParams::create());

        $rowConv1AfterThirdSync = $this->findPricingRowIncludingDeletedByConvenioRemoteId($procedimentoTipo->getId(), 'conv-1');
        $this->assertNotNull($rowConv1AfterThirdSync);
        $this->assertFalse((bool) $rowConv1AfterThirdSync->get('deleted'));
        $this->assertSame(180.00, $rowConv1AfterThirdSync->get('precoPaciente'));
        $this->assertSame('proc-conv-1b', $rowConv1AfterThirdSync->get('codigoTipoProcedimentoConvenio'));
    }

    public function testPricingSyncFailsWhenApiAndWebCredentialsAreNotPaired(): void
    {
        $apiTeam = $this->createTeam('cnn-proc-api-only');
        $webTeam = $this->createTeam('cnn-proc-web-only');
        $this->createApiCredentialForTeam($apiTeam);
        $this->createWebCredentialForTeam($webTeam);

        $this->useMockClients(
            [
                'proc-mismatch' => [
                    'procedimentoTipoId' => 'proc-mismatch',
                    'name' => 'Procedimento Mismatch',
                    'ativo' => true,
                    'especialidades' => ['X'],
                ],
            ],
            [],
            [],
        );

        $procedimentoTipo = $this->createProcedimentoTipo([
            'procedimentoTipoId' => 'proc-mismatch',
            'teamsIds' => [$apiTeam->getId(), $webTeam->getId()],
        ]);

        $reloaded = $this->getEntityManager()->getEntityById('FeatureIntegrationClinicaNasNuvensProcedimentoTipo', $procedimentoTipo->getId());
        $this->assertNotNull($reloaded);
        $this->assertSame('error', $reloaded->get('syncStatus'));

        $pricingRows = $this->getEntityManager()
            ->getRDBRepository('FeatureIntegrationClinicaNasNuvensProcedimentoConvenio')
            ->where([
                'procedimentoTipoId' => $procedimentoTipo->getId(),
                'deleted' => false,
            ])
            ->find();

        $count = 0;

        foreach ($pricingRows as $unused) {
            $count++;
        }

        $this->assertSame(0, $count);
    }

    /**
     * @param array<string, array<string, mixed>> $procedimentoTipoPayloadMap
     * @param array<string, array<string, mixed>> $convenioTipoPayloadMap
     * @param array<string, array<int, array<int, array<string, mixed>>>> $pricingSnapshotsByProcedimentoId
     */
    private function useMockClients(
        array $procedimentoTipoPayloadMap,
        array $convenioTipoPayloadMap,
        array $pricingSnapshotsByProcedimentoId,
    ): void {
        $apiClient = new FakeProcedimentoTipoConvenioSyncApiClient($procedimentoTipoPayloadMap, $convenioTipoPayloadMap);
        $webClient = new FakeProcedimentoTipoConvenioSyncWebClient($pricingSnapshotsByProcedimentoId);

        $app = $this->createApplication(
            binding: new class ($apiClient, $webClient) implements BindingProcessor {
                public function __construct(
                    private FakeProcedimentoTipoConvenioSyncApiClient $apiClient,
                    private FakeProcedimentoTipoConvenioSyncWebClient $webClient,
                ) {
                }

                public function process(Binder $binder): void
                {
                    $binder->bindInstance(ClinicaNasNuvensApiClient::class, $this->apiClient);
                    $binder->bindInstance(ClinicaNasNuvensWebClient::class, $this->webClient);
                }
            },
        );

        $this->setApplication($app);
    }

    private function getEntityManagerInstance(): EntityManager
    {
        return $this->getEntityManager();
    }

    private function getProcedimentoTipoService(): Service
    {
        return $this->getContainer()
            ->getByClass(ServiceContainer::class)
            ->get('FeatureIntegrationClinicaNasNuvensProcedimentoTipo');
    }

    /**
     * @param array<string, mixed> $data
     */
    private function createProcedimentoTipo(array $data): Entity
    {
        return $this->getProcedimentoTipoService()->create((object) $data, CreateParams::create());
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

    private function findPricingRowIncludingDeletedByConvenioRemoteId(
        string $procedimentoTipoLocalId,
        string $convenioTipoRemoteId,
    ): ?Entity {
        $convenioTipo = $this->getEntityManagerInstance()
            ->getRDBRepository('FeatureIntegrationClinicaNasNuvensConvenioTipo')
            ->where([
                'convenioTipoId' => $convenioTipoRemoteId,
                'deleted' => false,
            ])
            ->findOne();

        if (!$convenioTipo) {
            return null;
        }

        $query = $this->getEntityManagerInstance()
            ->getQueryBuilder()
            ->select()
            ->from('FeatureIntegrationClinicaNasNuvensProcedimentoConvenio')
            ->where([
                'procedimentoTipoId' => $procedimentoTipoLocalId,
                'convenioTipoId' => $convenioTipo->getId(),
            ])
            ->withDeleted()
            ->build();

        return $this->getEntityManagerInstance()
            ->getRDBRepository('FeatureIntegrationClinicaNasNuvensProcedimentoConvenio')
            ->clone($query)
            ->findOne();
    }
}

class FakeProcedimentoTipoConvenioSyncApiClient extends ClinicaNasNuvensApiClient
{
    /**
     * @param array<string, array<string, mixed>> $procedimentoTipoPayloadMap
     * @param array<string, array<string, mixed>> $convenioTipoPayloadMap
     */
    public function __construct(
        private array $procedimentoTipoPayloadMap,
        private array $convenioTipoPayloadMap,
    ) {
    }

    public function getTipoProcedimentoById(Entity $credential, string $procedimentoTipoId): array
    {
        if (!array_key_exists($procedimentoTipoId, $this->procedimentoTipoPayloadMap)) {
            throw new \RuntimeException("Missing fake procedimento tipo payload for '{$procedimentoTipoId}'.");
        }

        return $this->procedimentoTipoPayloadMap[$procedimentoTipoId];
    }

    public function getTipoConvenioById(Entity $credential, string $convenioTipoId): array
    {
        if (!array_key_exists($convenioTipoId, $this->convenioTipoPayloadMap)) {
            throw new \RuntimeException("Missing fake convenio tipo payload for '{$convenioTipoId}'.");
        }

        return $this->convenioTipoPayloadMap[$convenioTipoId];
    }
}

class FakeProcedimentoTipoConvenioSyncWebClient extends ClinicaNasNuvensWebClient
{
    /** @param array<string, array<int, array<int, array<string, mixed>>>> $pricingSnapshotsByProcedimentoId */
    public function __construct(private array $pricingSnapshotsByProcedimentoId)
    {
    }

    public function getProcedimentoConvenioPricingByProcedimentoId(Entity $credential, string $procedimentoTipoId): array
    {
        $snapshots = $this->pricingSnapshotsByProcedimentoId[$procedimentoTipoId] ?? [];

        if ($snapshots === []) {
            return [
                'rows' => [],
                'telemetry' => [
                    'attempts' => 1,
                    'rowsParsed' => 0,
                ],
            ];
        }

        $snapshot = array_shift($snapshots);
        $this->pricingSnapshotsByProcedimentoId[$procedimentoTipoId] = $snapshots;

        return [
            'rows' => $snapshot,
            'telemetry' => [
                'attempts' => 1,
                'rowsParsed' => count($snapshot),
            ],
        ];
    }
}

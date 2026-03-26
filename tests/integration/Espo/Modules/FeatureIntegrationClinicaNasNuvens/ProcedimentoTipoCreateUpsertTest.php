<?php

namespace tests\integration\Espo\Modules\FeatureIntegrationClinicaNasNuvens;

use Espo\Core\Binding\Binder;
use Espo\Core\Binding\BindingProcessor;
use Espo\Core\Record\CreateParams;
use Espo\Core\Record\DeleteParams;
use Espo\Core\Record\Service;
use Espo\Core\Record\ServiceContainer;
use Espo\Modules\FeatureIntegrationClinicaNasNuvens\Services\ClinicaNasNuvensApiClient;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use tests\integration\Core\BaseTestCase;

class ProcedimentoTipoCreateUpsertTest extends BaseTestCase
{
    public function testCreateReusesExistingActiveProcedimentoTipoAndMergesTeams(): void
    {
        $teamA = $this->createTeam('cnn-proc-a');
        $teamB = $this->createTeam('cnn-proc-b');
        $credential = $this->createApiCredentialForTeam($teamA);

        $this->useMockApiClient([
            'proc-1' => [
                'procedimentoTipoId' => 'proc-1',
                'name' => 'Procedimento 1',
                'ativo' => true,
                'especialidades' => ['Odonto'],
            ],
        ]);

        $existing = $this->getEntityManager()->createEntity('FeatureIntegrationClinicaNasNuvensProcedimentoTipo', [
            'name' => 'Procedimento Existing',
            'procedimentoTipoId' => 'proc-1',
            'credentialId' => $credential->getId(),
            'teamsIds' => [$teamA->getId()],
        ]);

        $created = $this->createProcedimentoTipo([
            'procedimentoTipoId' => 'proc-1',
            'teamsIds' => [$teamA->getId(), $teamB->getId()],
        ]);

        $this->assertSame($existing->getId(), $created->getId());

        $reloaded = $this->getEntityManager()->getEntityById('FeatureIntegrationClinicaNasNuvensProcedimentoTipo', $existing->getId());
        $this->assertNotNull($reloaded);
        $this->assertEqualsCanonicalizing([$teamA->getId(), $teamB->getId()], $reloaded->get('teamsIds'));

        $pricingRows = $this->getEntityManager()
            ->getRDBRepository('FeatureIntegrationClinicaNasNuvensProcedimentoConvenio')
            ->where([
                'procedimentoTipoId' => $existing->getId(),
                'deleted' => false,
            ])
            ->find();

        $count = 0;

        foreach ($pricingRows as $unused) {
            $count++;
        }

        $this->assertSame(0, $count);
    }

    public function testCreateRestoresSoftDeletedProcedimentoTipoAndMergesTeams(): void
    {
        $teamA = $this->createTeam('cnn-proc-soft-a');
        $teamB = $this->createTeam('cnn-proc-soft-b');
        $credential = $this->createApiCredentialForTeam($teamA);

        $this->useMockApiClient([
            'proc-soft' => [
                'procedimentoTipoId' => 'proc-soft',
                'name' => 'Procedimento Soft',
                'ativo' => true,
                'especialidades' => ['Clinica Geral'],
            ],
        ]);

        $existing = $this->getEntityManager()->createEntity('FeatureIntegrationClinicaNasNuvensProcedimentoTipo', [
            'procedimentoTipoId' => 'proc-soft',
            'credentialId' => $credential->getId(),
            'teamsIds' => [$teamA->getId()],
        ]);

        $this->getProcedimentoTipoService()->delete($existing->getId(), DeleteParams::create());

        $created = $this->createProcedimentoTipo([
            'procedimentoTipoId' => 'proc-soft',
            'teamsIds' => [$teamA->getId(), $teamB->getId()],
        ]);

        $this->assertSame($existing->getId(), $created->getId());

        $reloaded = $this->getEntityManager()->getEntityById('FeatureIntegrationClinicaNasNuvensProcedimentoTipo', $existing->getId());
        $this->assertNotNull($reloaded);
        $this->assertFalse((bool) $reloaded->get('deleted'));
        $this->assertEqualsCanonicalizing([$teamA->getId(), $teamB->getId()], $reloaded->get('teamsIds'));
    }

    public function testCreateDuplicateLikeFlowKeepsSingleAnchor(): void
    {
        $team = $this->createTeam('cnn-proc-dup');
        $credential = $this->createApiCredentialForTeam($team);

        $this->useMockApiClient([
            'proc-dup' => [
                'procedimentoTipoId' => 'proc-dup',
                'name' => 'Procedimento Duplicado',
                'ativo' => true,
                'especialidades' => ['A'],
            ],
        ]);

        $a = $this->createProcedimentoTipo([
            'procedimentoTipoId' => 'proc-dup',
            'teamsIds' => [$team->getId()],
        ]);

        $b = $this->createProcedimentoTipo([
            'procedimentoTipoId' => 'proc-dup',
            'teamsIds' => [$team->getId()],
        ]);

        $this->assertSame($a->getId(), $b->getId());

        $rows = $this->getEntityManager()
            ->getRDBRepository('FeatureIntegrationClinicaNasNuvensProcedimentoTipo')
            ->where([
                'procedimentoTipoId' => 'proc-dup',
                'credentialId' => $credential->getId(),
                'deleted' => false,
            ])
            ->find();

        $count = 0;

        foreach ($rows as $unused) {
            $count++;
        }

        $this->assertSame(1, $count);
    }

    /**
     * @param array<string, array<string, mixed>> $payloadMap
     */
    private function useMockApiClient(array $payloadMap): void
    {
        $apiClient = new FakeProcedimentoTipoUpsertClinicaNasNuvensApiClient($payloadMap);

        $app = $this->createApplication(
            binding: new class ($apiClient) implements BindingProcessor {
                public function __construct(private FakeProcedimentoTipoUpsertClinicaNasNuvensApiClient $apiClient)
                {
                }

                public function process(Binder $binder): void
                {
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

class FakeProcedimentoTipoUpsertClinicaNasNuvensApiClient extends ClinicaNasNuvensApiClient
{
    /** @param array<string, array<string, mixed>> $payloadMap */
    public function __construct(private array $payloadMap)
    {
    }

    public function getTipoProcedimentoById(Entity $credential, string $procedimentoTipoId): array
    {
        if (!array_key_exists($procedimentoTipoId, $this->payloadMap)) {
            throw new \RuntimeException("Missing fake procedimento tipo payload for '{$procedimentoTipoId}'.");
        }

        return $this->payloadMap[$procedimentoTipoId];
    }
}

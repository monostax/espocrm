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

class ConvenioTipoCreateUpsertTest extends BaseTestCase
{
    public function testCreateReusesExistingActiveConvenioTipoAndMergesTeams(): void
    {
        $teamA = $this->createTeam('cnn-conv-a');
        $teamB = $this->createTeam('cnn-conv-b');
        $credential = $this->createApiCredentialForTeam($teamA);

        $this->useMockApiClient([
            'conv-1' => [
                'convenioTipoId' => 'conv-1',
                'name' => 'Convenio 1',
                'ativo' => true,
                'beneficio' => false,
                'particular' => true,
            ],
        ]);

        $existing = $this->getEntityManager()->createEntity('FeatureIntegrationClinicaNasNuvensConvenioTipo', [
            'name' => 'Convenio Existing',
            'convenioTipoId' => 'conv-1',
            'credentialId' => $credential->getId(),
            'teamsIds' => [$teamA->getId()],
        ]);

        $created = $this->createConvenioTipo([
            'convenioTipoId' => 'conv-1',
            'teamsIds' => [$teamA->getId(), $teamB->getId()],
        ]);

        $this->assertSame($existing->getId(), $created->getId());

        $reloaded = $this->getEntityManager()->getEntityById('FeatureIntegrationClinicaNasNuvensConvenioTipo', $existing->getId());
        $this->assertNotNull($reloaded);
        $this->assertEqualsCanonicalizing([$teamA->getId(), $teamB->getId()], $reloaded->get('teamsIds'));
    }

    public function testCreateRestoresSoftDeletedConvenioTipoAndMergesTeams(): void
    {
        $teamA = $this->createTeam('cnn-conv-soft-a');
        $teamB = $this->createTeam('cnn-conv-soft-b');
        $credential = $this->createApiCredentialForTeam($teamA);

        $this->useMockApiClient([
            'conv-soft' => [
                'convenioTipoId' => 'conv-soft',
                'name' => 'Convenio Soft',
                'ativo' => true,
                'beneficio' => true,
                'particular' => false,
            ],
        ]);

        $existing = $this->getEntityManager()->createEntity('FeatureIntegrationClinicaNasNuvensConvenioTipo', [
            'convenioTipoId' => 'conv-soft',
            'credentialId' => $credential->getId(),
            'teamsIds' => [$teamA->getId()],
        ]);

        $this->getConvenioTipoService()->delete($existing->getId(), DeleteParams::create());

        $created = $this->createConvenioTipo([
            'convenioTipoId' => 'conv-soft',
            'teamsIds' => [$teamA->getId(), $teamB->getId()],
        ]);

        $this->assertSame($existing->getId(), $created->getId());

        $reloaded = $this->getEntityManager()->getEntityById('FeatureIntegrationClinicaNasNuvensConvenioTipo', $existing->getId());
        $this->assertNotNull($reloaded);
        $this->assertFalse((bool) $reloaded->get('deleted'));
        $this->assertEqualsCanonicalizing([$teamA->getId(), $teamB->getId()], $reloaded->get('teamsIds'));
    }

    public function testCreateDuplicateLikeFlowKeepsSingleAnchor(): void
    {
        $team = $this->createTeam('cnn-conv-dup');
        $credential = $this->createApiCredentialForTeam($team);

        $this->useMockApiClient([
            'conv-dup' => [
                'convenioTipoId' => 'conv-dup',
                'name' => 'Convenio Duplicado',
                'ativo' => true,
                'beneficio' => false,
                'particular' => true,
            ],
        ]);

        $a = $this->createConvenioTipo([
            'convenioTipoId' => 'conv-dup',
            'teamsIds' => [$team->getId()],
        ]);

        $b = $this->createConvenioTipo([
            'convenioTipoId' => 'conv-dup',
            'teamsIds' => [$team->getId()],
        ]);

        $this->assertSame($a->getId(), $b->getId());

        $rows = $this->getEntityManager()
            ->getRDBRepository('FeatureIntegrationClinicaNasNuvensConvenioTipo')
            ->where([
                'convenioTipoId' => 'conv-dup',
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
        $apiClient = new FakeConvenioTipoUpsertClinicaNasNuvensApiClient($payloadMap);

        $app = $this->createApplication(
            binding: new class ($apiClient) implements BindingProcessor {
                public function __construct(private FakeConvenioTipoUpsertClinicaNasNuvensApiClient $apiClient)
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

    private function getConvenioTipoService(): Service
    {
        return $this->getContainer()
            ->getByClass(ServiceContainer::class)
            ->get('FeatureIntegrationClinicaNasNuvensConvenioTipo');
    }

    /**
     * @param array<string, mixed> $data
     */
    private function createConvenioTipo(array $data): Entity
    {
        return $this->getConvenioTipoService()->create((object) $data, CreateParams::create());
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

class FakeConvenioTipoUpsertClinicaNasNuvensApiClient extends ClinicaNasNuvensApiClient
{
    /** @param array<string, array<string, mixed>> $payloadMap */
    public function __construct(private array $payloadMap)
    {
    }

    public function getTipoConvenioById(Entity $credential, string $convenioTipoId): array
    {
        if (!array_key_exists($convenioTipoId, $this->payloadMap)) {
            throw new \RuntimeException("Missing fake convenio tipo payload for '{$convenioTipoId}'.");
        }

        return $this->payloadMap[$convenioTipoId];
    }
}

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

class ProfissionalCreateUpsertTest extends BaseTestCase
{
    public function testCreateReusesExistingActiveProfissionalAndMergesTeams(): void
    {
        $teamA = $this->createTeam('cnn-prof-a');
        $teamB = $this->createTeam('cnn-prof-b');
        $credential = $this->createApiCredentialForTeam($teamA);

        $this->useMockApiClient([
            'prof-1' => [
                'profissionalId' => 'prof-1',
                'tipoExecutor' => 'PROFISSIONAL',
                'name' => 'Profissional 1',
                'profissionalCodigo' => '43251',
                'especialidades' => [
                    ['id' => 'esp-1', 'nome' => 'In loco'],
                ],
                'especialidadesTexto' => 'In loco',
            ],
        ]);

        $existing = $this->getEntityManager()->createEntity('FeatureIntegrationClinicaNasNuvensProfissional', [
            'name' => 'Profissional Existing',
            'profissionalId' => 'prof-1',
            'credentialId' => $credential->getId(),
            'teamsIds' => [$teamA->getId()],
        ]);

        $created = $this->createProfissional([
            'profissionalId' => 'prof-1',
            'teamsIds' => [$teamA->getId(), $teamB->getId()],
        ]);

        $this->assertSame($existing->getId(), $created->getId());

        $reloaded = $this->getEntityManager()->getEntityById('FeatureIntegrationClinicaNasNuvensProfissional', $existing->getId());
        $this->assertNotNull($reloaded);
        $this->assertEqualsCanonicalizing([$teamA->getId(), $teamB->getId()], $reloaded->get('teamsIds'));
        $this->assertSame('43251', $reloaded->get('profissionalCodigo'));
        $this->assertSame('In loco', $reloaded->get('especialidadesTexto'));
    }

    public function testCreateRestoresSoftDeletedProfissionalAndMergesTeams(): void
    {
        $teamA = $this->createTeam('cnn-prof-soft-a');
        $teamB = $this->createTeam('cnn-prof-soft-b');
        $credential = $this->createApiCredentialForTeam($teamA);

        $this->useMockApiClient([
            'prof-soft' => [
                'profissionalId' => 'prof-soft',
                'tipoExecutor' => 'PROFISSIONAL',
                'name' => 'Profissional Soft',
            ],
        ]);

        $existing = $this->getEntityManager()->createEntity('FeatureIntegrationClinicaNasNuvensProfissional', [
            'profissionalId' => 'prof-soft',
            'credentialId' => $credential->getId(),
            'teamsIds' => [$teamA->getId()],
        ]);

        $this->getProfissionalService()->delete($existing->getId(), DeleteParams::create());

        $created = $this->createProfissional([
            'profissionalId' => 'prof-soft',
            'teamsIds' => [$teamA->getId(), $teamB->getId()],
        ]);

        $this->assertSame($existing->getId(), $created->getId());

        $reloaded = $this->getEntityManager()->getEntityById('FeatureIntegrationClinicaNasNuvensProfissional', $existing->getId());
        $this->assertNotNull($reloaded);
        $this->assertFalse((bool) $reloaded->get('deleted'));
        $this->assertEqualsCanonicalizing([$teamA->getId(), $teamB->getId()], $reloaded->get('teamsIds'));
    }

    public function testCreateDuplicateLikeFlowKeepsSingleAnchor(): void
    {
        $team = $this->createTeam('cnn-prof-dup');
        $credential = $this->createApiCredentialForTeam($team);

        $this->useMockApiClient([
            'prof-dup' => [
                'profissionalId' => 'prof-dup',
                'tipoExecutor' => 'PROFISSIONAL',
                'name' => 'Profissional Duplicado',
            ],
        ]);

        $a = $this->createProfissional([
            'profissionalId' => 'prof-dup',
            'teamsIds' => [$team->getId()],
        ]);

        $b = $this->createProfissional([
            'profissionalId' => 'prof-dup',
            'teamsIds' => [$team->getId()],
        ]);

        $this->assertSame($a->getId(), $b->getId());

        $rows = $this->getEntityManager()
            ->getRDBRepository('FeatureIntegrationClinicaNasNuvensProfissional')
            ->where([
                'profissionalId' => 'prof-dup',
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

    public function testCreateByIdPessoaExecutorResolvesProfissionalIdAndCreatesAnchor(): void
    {
        $team = $this->createTeam('cnn-prof-pessoa-create');
        $credential = $this->createApiCredentialForTeam($team);

        $this->useMockApiClient([
            'prof-resolved' => [
                'profissionalId' => 'prof-resolved',
                'idPessoa' => '9298413',
                'tipoExecutor' => 'PROFISSIONAL',
                'name' => 'Profissional Resolvido',
            ],
        ], [
            '9298413' => 'prof-resolved',
        ]);

        $created = $this->createProfissional([
            'idPessoaExecutor' => '9298413',
            'teamsIds' => [$team->getId()],
        ]);

        $this->assertSame('prof-resolved', $created->get('profissionalId'));
        $this->assertSame($credential->getId(), $created->get('credentialId'));
        $this->assertSame('9298413', $created->get('idPessoa'));
    }

    /**
     * @param array<string, array<string, mixed>> $payloadMap
     * @param array<string, string> $idPessoaToProfissionalIdMap
     */
    private function useMockApiClient(array $payloadMap, array $idPessoaToProfissionalIdMap = []): void
    {
        $apiClient = new FakeProfissionalUpsertClinicaNasNuvensApiClient($payloadMap, $idPessoaToProfissionalIdMap);

        $app = $this->createApplication(
            binding: new class ($apiClient) implements BindingProcessor {
                public function __construct(private FakeProfissionalUpsertClinicaNasNuvensApiClient $apiClient)
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

    private function getProfissionalService(): Service
    {
        return $this->getContainer()
            ->getByClass(ServiceContainer::class)
            ->get('FeatureIntegrationClinicaNasNuvensProfissional');
    }

    /**
     * @param array<string, mixed> $data
     */
    private function createProfissional(array $data): Entity
    {
        return $this->getProfissionalService()->create((object) $data, CreateParams::create());
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

class FakeProfissionalUpsertClinicaNasNuvensApiClient extends ClinicaNasNuvensApiClient
{
    /** @param array<string, array<string, mixed>> $payloadMap */
    public function __construct(
        private array $payloadMap,
        /** @var array<string, string> */
        private array $idPessoaToProfissionalIdMap = [],
    )
    {
    }

    public function getExecutorAgendaById(Entity $credential, string $profissionalId): array
    {
        if (!array_key_exists($profissionalId, $this->payloadMap)) {
            throw new \RuntimeException("Missing fake profissional payload for '{$profissionalId}'.");
        }

        return $this->payloadMap[$profissionalId];
    }

    public function resolveExecutorAgendaIdByPessoaId(Entity $credential, string $idPessoa): ?string
    {
        return $this->idPessoaToProfissionalIdMap[$idPessoa] ?? null;
    }
}

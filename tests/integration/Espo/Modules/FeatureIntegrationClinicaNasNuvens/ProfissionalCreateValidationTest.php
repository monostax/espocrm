<?php

namespace tests\integration\Espo\Modules\FeatureIntegrationClinicaNasNuvens;

use Espo\Core\Binding\Binder;
use Espo\Core\Binding\BindingProcessor;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Record\CreateParams;
use Espo\Core\Record\Service;
use Espo\Core\Record\ServiceContainer;
use Espo\Modules\FeatureIntegrationClinicaNasNuvens\Services\ClinicaNasNuvensApiClient;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use tests\integration\Core\BaseTestCase;

class ProfissionalCreateValidationTest extends BaseTestCase
{
    public function testCreateRejectsInvalidProfissionalIdBeforeSave(): void
    {
        $team = $this->createTeam('cnn-prof-invalid-team');
        $this->createApiCredentialForTeam($team);

        $this->useMockApiClient([]);

        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage("Cannot create profissional anchor 'prof-invalid'");

        try {
            $this->createProfissional([
                'profissionalId' => 'prof-invalid',
                'teamsIds' => [$team->getId()],
            ]);
        } finally {
            $created = $this->getEntityManagerInstance()
                ->getRDBRepository('FeatureIntegrationClinicaNasNuvensProfissional')
                ->where([
                    'profissionalId' => 'prof-invalid',
                    'deleted' => false,
                ])
                ->findOne();

            $this->assertNull($created);
        }
    }

    public function testCreateRejectsMismatchedRemoteProfissionalIdBeforeSave(): void
    {
        $team = $this->createTeam('cnn-prof-mismatch-team');
        $this->createApiCredentialForTeam($team);

        $this->useMockApiClient([
            'prof-1' => [
                'profissionalId' => 'prof-2',
                'tipoExecutor' => 'PROFISSIONAL',
                'name' => 'Profissional Divergente',
            ],
        ]);

        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage("Cannot create profissional anchor 'prof-1'");

        try {
            $this->createProfissional([
                'profissionalId' => 'prof-1',
                'teamsIds' => [$team->getId()],
            ]);
        } finally {
            $created = $this->getEntityManagerInstance()
                ->getRDBRepository('FeatureIntegrationClinicaNasNuvensProfissional')
                ->where([
                    'profissionalId' => 'prof-1',
                    'deleted' => false,
                ])
                ->findOne();

            $this->assertNull($created);
        }
    }

    public function testCreateRejectsNonProfissionalTipoExecutorBeforeSave(): void
    {
        $team = $this->createTeam('cnn-prof-tipo-team');
        $this->createApiCredentialForTeam($team);

        $this->useMockApiClient([
            'prof-3' => [
                'profissionalId' => 'prof-3',
                'tipoExecutor' => 'SALA',
                'name' => 'Executor Sala',
            ],
        ]);

        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage("Cannot create profissional anchor 'prof-3'");

        try {
            $this->createProfissional([
                'profissionalId' => 'prof-3',
                'teamsIds' => [$team->getId()],
            ]);
        } finally {
            $created = $this->getEntityManagerInstance()
                ->getRDBRepository('FeatureIntegrationClinicaNasNuvensProfissional')
                ->where([
                    'profissionalId' => 'prof-3',
                    'deleted' => false,
                ])
                ->findOne();

            $this->assertNull($created);
        }
    }

    public function testCreateByIdPessoaExecutorRejectsWhenExecutorIdCannotBeResolved(): void
    {
        $team = $this->createTeam('cnn-prof-pessoa-unresolved-team');
        $this->createApiCredentialForTeam($team);

        $this->useMockApiClient([], []);

        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage("Cannot create profissional anchor from idPessoa '999991'");

        try {
            $this->createProfissional([
                'idPessoaExecutor' => '999991',
                'teamsIds' => [$team->getId()],
            ]);
        } finally {
            $created = $this->getEntityManagerInstance()
                ->getRDBRepository('FeatureIntegrationClinicaNasNuvensProfissional')
                ->where([
                    'idPessoa' => '999991',
                    'deleted' => false,
                ])
                ->findOne();

            $this->assertNull($created);
        }
    }

    /**
     * @param array<string, array<string, mixed>> $payloadMap
     * @param array<string, string> $idPessoaToProfissionalIdMap
     */
    private function useMockApiClient(array $payloadMap, array $idPessoaToProfissionalIdMap = []): void
    {
        $apiClient = new FakeProfissionalValidationClinicaNasNuvensApiClient($payloadMap, $idPessoaToProfissionalIdMap);

        $app = $this->createApplication(
            binding: new class ($apiClient) implements BindingProcessor {
                public function __construct(private FakeProfissionalValidationClinicaNasNuvensApiClient $apiClient)
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

class FakeProfissionalValidationClinicaNasNuvensApiClient extends ClinicaNasNuvensApiClient
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

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

class PacienteCreateValidationTest extends BaseTestCase
{
    public function testCreateRejectsInvalidPacienteIdBeforeSave(): void
    {
        $team = $this->createTeam('cnn-paciente-invalid-team');
        $this->createApiCredentialForTeam($team);

        $this->useMockApiClient([]);

        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage("Cannot create paciente anchor 'paciente-invalid'");

        try {
            $this->createPaciente([
                'pacienteId' => 'paciente-invalid',
                'teamsIds' => [$team->getId()],
            ]);
        } finally {
            $created = $this->getEntityManagerInstance()
                ->getRDBRepository('FeatureIntegrationClinicaNasNuvensPaciente')
                ->where([
                    'pacienteId' => 'paciente-invalid',
                    'deleted' => false,
                ])
                ->findOne();

            $this->assertNull($created);
        }
    }

    /**
     * @param array<string, array<string, mixed>> $pacientePayloadMap
     */
    private function useMockApiClient(array $pacientePayloadMap): void
    {
        $apiClient = new FakePacienteValidationClinicaNasNuvensApiClient($pacientePayloadMap);

        $app = $this->createApplication(
            binding: new class ($apiClient) implements BindingProcessor {
                public function __construct(private FakePacienteValidationClinicaNasNuvensApiClient $apiClient)
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

    private function getPacienteService(): Service
    {
        return $this->getContainer()
            ->getByClass(ServiceContainer::class)
            ->get('FeatureIntegrationClinicaNasNuvensPaciente');
    }

    /**
     * @param array<string, mixed> $data
     */
    private function createPaciente(array $data): Entity
    {
        return $this->getPacienteService()->create((object) $data, CreateParams::create());
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

class FakePacienteValidationClinicaNasNuvensApiClient extends ClinicaNasNuvensApiClient
{
    /** @param array<string, array<string, mixed>> $payloadMap */
    public function __construct(private array $payloadMap)
    {
    }

    public function getPacienteById(Entity $credential, string $pacienteId): array
    {
        if (!array_key_exists($pacienteId, $this->payloadMap)) {
            throw new \RuntimeException("Missing fake paciente payload for '{$pacienteId}'.");
        }

        return $this->payloadMap[$pacienteId];
    }
}

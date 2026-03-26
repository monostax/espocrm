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

class ConvenioTipoCreateValidationTest extends BaseTestCase
{
    public function testCreateRejectsInvalidConvenioTipoIdBeforeSave(): void
    {
        $team = $this->createTeam('cnn-convenio-invalid-team');
        $this->createApiCredentialForTeam($team);

        $this->useMockApiClient([]);

        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage("Cannot create convenio tipo anchor 'convenio-invalid'");

        try {
            $this->createConvenioTipo([
                'convenioTipoId' => 'convenio-invalid',
                'teamsIds' => [$team->getId()],
            ]);
        } finally {
            $created = $this->getEntityManagerInstance()
                ->getRDBRepository('FeatureIntegrationClinicaNasNuvensConvenioTipo')
                ->where([
                    'convenioTipoId' => 'convenio-invalid',
                    'deleted' => false,
                ])
                ->findOne();

            $this->assertNull($created);
        }
    }

    public function testCreateRejectsMismatchedRemoteConvenioTipoIdBeforeSave(): void
    {
        $team = $this->createTeam('cnn-convenio-mismatch-team');
        $this->createApiCredentialForTeam($team);

        $this->useMockApiClient([
            'convenio-1' => [
                'convenioTipoId' => 'convenio-2',
                'name' => 'Convenio Divergente',
            ],
        ]);

        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage("Cannot create convenio tipo anchor 'convenio-1'");

        try {
            $this->createConvenioTipo([
                'convenioTipoId' => 'convenio-1',
                'teamsIds' => [$team->getId()],
            ]);
        } finally {
            $created = $this->getEntityManagerInstance()
                ->getRDBRepository('FeatureIntegrationClinicaNasNuvensConvenioTipo')
                ->where([
                    'convenioTipoId' => 'convenio-1',
                    'deleted' => false,
                ])
                ->findOne();

            $this->assertNull($created);
        }
    }

    /**
     * @param array<string, array<string, mixed>> $payloadMap
     */
    private function useMockApiClient(array $payloadMap): void
    {
        $apiClient = new FakeConvenioTipoValidationClinicaNasNuvensApiClient($payloadMap);

        $app = $this->createApplication(
            binding: new class ($apiClient) implements BindingProcessor {
                public function __construct(private FakeConvenioTipoValidationClinicaNasNuvensApiClient $apiClient)
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

class FakeConvenioTipoValidationClinicaNasNuvensApiClient extends ClinicaNasNuvensApiClient
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

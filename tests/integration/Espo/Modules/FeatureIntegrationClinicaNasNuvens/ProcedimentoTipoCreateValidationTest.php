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

class ProcedimentoTipoCreateValidationTest extends BaseTestCase
{
    public function testCreateRejectsInvalidProcedimentoTipoIdBeforeSave(): void
    {
        $team = $this->createTeam('cnn-procedimento-invalid-team');
        $this->createApiCredentialForTeam($team);

        $this->useMockApiClient([]);

        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage("Cannot create procedimento tipo anchor 'procedimento-invalid'");

        try {
            $this->createProcedimentoTipo([
                'procedimentoTipoId' => 'procedimento-invalid',
                'teamsIds' => [$team->getId()],
            ]);
        } finally {
            $created = $this->getEntityManagerInstance()
                ->getRDBRepository('FeatureIntegrationClinicaNasNuvensProcedimentoTipo')
                ->where([
                    'procedimentoTipoId' => 'procedimento-invalid',
                    'deleted' => false,
                ])
                ->findOne();

            $pricing = $this->getEntityManagerInstance()
                ->getRDBRepository('FeatureIntegrationClinicaNasNuvensProcedimentoConvenio')
                ->where(['deleted' => false])
                ->find();

            $pricingCount = 0;

            foreach ($pricing as $unused) {
                $pricingCount++;
            }

            $this->assertNull($created);
            $this->assertSame(0, $pricingCount);
        }
    }

    public function testCreateRejectsMismatchedRemoteProcedimentoTipoIdBeforeSave(): void
    {
        $team = $this->createTeam('cnn-procedimento-mismatch-team');
        $this->createApiCredentialForTeam($team);

        $this->useMockApiClient([
            'procedimento-1' => [
                'procedimentoTipoId' => 'procedimento-2',
                'name' => 'Procedimento Divergente',
            ],
        ]);

        $this->expectException(BadRequest::class);
        $this->expectExceptionMessage("Cannot create procedimento tipo anchor 'procedimento-1'");

        try {
            $this->createProcedimentoTipo([
                'procedimentoTipoId' => 'procedimento-1',
                'teamsIds' => [$team->getId()],
            ]);
        } finally {
            $created = $this->getEntityManagerInstance()
                ->getRDBRepository('FeatureIntegrationClinicaNasNuvensProcedimentoTipo')
                ->where([
                    'procedimentoTipoId' => 'procedimento-1',
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
        $apiClient = new FakeProcedimentoTipoValidationClinicaNasNuvensApiClient($payloadMap);

        $app = $this->createApplication(
            binding: new class ($apiClient) implements BindingProcessor {
                public function __construct(private FakeProcedimentoTipoValidationClinicaNasNuvensApiClient $apiClient)
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

class FakeProcedimentoTipoValidationClinicaNasNuvensApiClient extends ClinicaNasNuvensApiClient
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

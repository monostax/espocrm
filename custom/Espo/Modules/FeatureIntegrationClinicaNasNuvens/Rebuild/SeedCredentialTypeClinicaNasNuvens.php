<?php

namespace Espo\Modules\FeatureIntegrationClinicaNasNuvens\Rebuild;

use Espo\Core\Rebuild\RebuildAction;
use Espo\Core\Utils\Log;
use Espo\ORM\EntityManager;

class SeedCredentialTypeClinicaNasNuvens implements RebuildAction
{
    public function __construct(
        private EntityManager $entityManager,
        private Log $log,
    ) {}

    public function process(): void
    {
        $this->log->info('FeatureIntegrationClinicaNasNuvens: Seeding credential type...');

        $schema = [
            'type' => 'object',
            'properties' => [
                'clientId' => ['type' => 'string', 'title' => 'Client ID'],
                'clientSecret' => ['type' => 'string', 'title' => 'Client Secret'],
                'clinicCid' => ['type' => 'string', 'title' => 'Clinic CID (clinicaNasNuvens-cid)'],
                'baseUrl' => [
                    'type' => 'string',
                    'title' => 'API Base URL',
                    'default' => 'https://api.clinicanasnuvens.com.br',
                ],
            ],
            'required' => ['clientId', 'clientSecret', 'clinicCid'],
        ];

        $config = [
            'name' => 'Clinica Nas Nuvens',
            'code' => 'clinicaNasNuvens',
            'category' => 'basicAuth',
            'description' => 'Clínica nas Nuvens API credentials (Basic Auth + clinic CID header).',
            'schema' => json_encode($schema),
            'encryptionFields' => json_encode(['clientSecret', 'clinicCid']),
            'requiresRotation' => true,
            'rotationDays' => 90,
            'isSystem' => true,
        ];

        $result = $this->seedCredentialType($config);

        $this->log->info("FeatureIntegrationClinicaNasNuvens: Credential type seeding completed ({$result})");
    }

    /**
     * @param array<string, mixed> $config
     */
    private function seedCredentialType(array $config): string
    {
        $existing = $this->entityManager
            ->getRDBRepository('CredentialType')
            ->where(['code' => $config['code']])
            ->findOne();

        if ($existing) {
            if (!$existing->get('isSystem')) {
                return 'skipped';
            }

            $existing->set([
                'name' => $config['name'],
                'category' => $config['category'],
                'description' => $config['description'] ?? null,
                'schema' => $config['schema'],
                'encryptionFields' => $config['encryptionFields'] ?? '[]',
                'requiresRotation' => $config['requiresRotation'] ?? false,
                'rotationDays' => $config['rotationDays'] ?? 90,
            ]);

            $this->entityManager->saveEntity($existing);

            return 'updated';
        }

        $credentialType = $this->entityManager->getEntity('CredentialType');
        $credentialType->set([
            'name' => $config['name'],
            'code' => $config['code'],
            'category' => $config['category'],
            'description' => $config['description'] ?? null,
            'schema' => $config['schema'],
            'encryptionFields' => $config['encryptionFields'] ?? '[]',
            'requiresRotation' => $config['requiresRotation'] ?? false,
            'rotationDays' => $config['rotationDays'] ?? 90,
            'isSystem' => $config['isSystem'] ?? false,
        ]);

        $this->entityManager->saveEntity($credentialType);

        return 'created';
    }
}

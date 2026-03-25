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
        $this->log->info('FeatureIntegrationClinicaNasNuvens: Seeding credential types...');

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

        $configList = [
            [
                'name' => 'Clinica Nas Nuvens',
                'code' => 'clinicaNasNuvens',
                'category' => 'basicAuth',
                'description' => 'Clínica nas Nuvens API credentials (Basic Auth + clinic CID header).',
                'schema' => json_encode($schema),
                'encryptionFields' => json_encode(['clientSecret', 'clinicCid']),
                'requiresRotation' => true,
                'rotationDays' => 90,
                'isSystem' => true,
            ],
            [
                'name' => 'Clinica Nas Nuvens (Web)',
                'code' => 'clinicaNasNuvens-web',
                'category' => 'formAuth',
                'description' => 'Clínica nas Nuvens web login session (email/password form auth).',
                'schema' => json_encode([
                    'type' => 'object',
                    'properties' => [
                        'username' => ['type' => 'string', 'title' => 'Username/Email'],
                        'password' => ['type' => 'string', 'title' => 'Password'],
                        'loginUrl' => [
                            'type' => 'string',
                            'title' => 'Login URL',
                            'default' => 'https://clinicanasnuvens.b2clogin.com/clinicanasnuvens.onmicrosoft.com/b2c_1_login/oauth2/v2.0/authorize?ope=openid+profile+offline_access+openid+bf9d0710-a7af-4af2-99ea-508a19f338d5&response_type=code&redirect_uri=https%3A%2F%2Fapp.clinicanasnuvens.com.br%2Fb2c%2Flogin&state=B2C_1_login&client_id=bf9d0710-a7af-4af2-99ea-508a19f338d5&response_mode=query',
                        ],
                        'usernameField' => ['type' => 'string', 'title' => 'Username Field Name', 'default' => 'email'],
                        'passwordField' => ['type' => 'string', 'title' => 'Password Field Name', 'default' => 'password'],
                        'additionalFields' => ['type' => 'object', 'title' => 'Additional Form Fields', 'additionalProperties' => true],
                        'testUrl' => ['type' => 'string', 'title' => 'Test URL for Health Check', 'default' => 'https://app.clinicanasnuvens.com.br/agenda/index'],
                        'sessionCookies' => ['type' => 'string', 'title' => 'Session Cookies (auto-managed)'],
                    ],
                    'required' => ['username', 'password', 'loginUrl'],
                ]),
                'encryptionFields' => json_encode(['password', 'sessionCookies']),
                'requiresRotation' => true,
                'rotationDays' => 90,
                'isSystem' => true,
            ],
        ];

        foreach ($configList as $config) {
            $result = $this->seedCredentialType($config);
            $this->log->info("FeatureIntegrationClinicaNasNuvens: Credential type '{$config['code']}' seeding completed ({$result})");
        }
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

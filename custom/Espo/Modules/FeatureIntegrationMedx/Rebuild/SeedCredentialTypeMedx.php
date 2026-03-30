<?php
namespace Espo\Modules\FeatureIntegrationMedx\Rebuild;
use Espo\Core\Rebuild\RebuildAction;
use Espo\Core\Utils\Log;
use Espo\ORM\EntityManager;
class SeedCredentialTypeMedx implements RebuildAction
{
    public function __construct(
        private EntityManager $entityManager,
        private Log $log,
    ) {}
    public function process(): void
    {
        $this->log->info('FeatureIntegrationMedx: Seeding credential types...');
        $configList = [
            [
                'name' => 'MEDX (Web)',
                'code' => 'medx-web',
                'category' => 'formAuth',
                'description' => 'MEDX web login session (email/password form auth with RSA-encrypted password and Bearer token).',
                'schema' => json_encode([
                    'type' => 'object',
                    'properties' => [
                        'username' => ['type' => 'string', 'title' => 'Email do Usuario'],
                        'password' => ['type' => 'string', 'title' => 'Senha'],
                        'loginUrl' => [
                            'type' => 'string',
                            'title' => 'Login URL',
                            'default' => 'https://v65.medx.med.br/Login_Unificado/loginUnificado.html',
                        ],
                        'usernameField' => ['type' => 'string', 'title' => 'Username Field Name', 'default' => 'emailUsuario'],
                        'passwordField' => ['type' => 'string', 'title' => 'Password Field Name', 'default' => 'senhaUsuario'],
                        'additionalFields' => ['type' => 'object', 'title' => 'Additional Form Fields', 'additionalProperties' => true],
                        'testUrl' => ['type' => 'string', 'title' => 'Test URL for Health Check', 'default' => 'https://v65.medx.med.br/api/security/getcurrentuser'],
                        'bearerToken' => ['type' => 'string', 'title' => 'Bearer Token (auto-managed)'],
                        'sessionCookies' => ['type' => 'string', 'title' => 'Session Cookies (auto-managed)'],
                    ],
                    'required' => ['username', 'password'],
                ]),
                'encryptionFields' => json_encode(['password', 'bearerToken', 'sessionCookies']),
                'requiresRotation' => true,
                'rotationDays' => 90,
                'isSystem' => true,
            ],
        ];
        foreach ($configList as $config) {
            $result = $this->seedCredentialType($config);
            $this->log->info("FeatureIntegrationMedx: Credential type '{$config['code']}' seeding completed ({$result})");
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
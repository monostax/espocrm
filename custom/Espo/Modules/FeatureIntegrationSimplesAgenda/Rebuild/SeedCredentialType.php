<?php

namespace Espo\Modules\FeatureIntegrationSimplesAgenda\Rebuild;

use Espo\Core\Rebuild\RebuildAction;
use Espo\Core\Utils\Log;
use Espo\ORM\EntityManager;

/**
 * Rebuild action to seed the SimplesAgenda credential type.
 * Runs automatically during system rebuild.
 */
class SeedCredentialType implements RebuildAction
{
    public function __construct(
        private EntityManager $entityManager,
        private Log $log
    ) {}

    public function process(): void
    {
        $this->log->info('FeatureIntegrationSimplesAgenda: Seeding credential type...');

        $config = [
            'name' => 'SimplesAgenda',
            'code' => 'simplesAgenda',
            'category' => 'formAuth',
            'description' => 'SimplesAgenda login credentials (email and password) for exporting client data.',
            'schema' => json_encode([
                'type' => 'object',
                'properties' => [
                    'username' => ['type' => 'string', 'title' => 'Email'],
                    'password' => ['type' => 'string', 'title' => 'Password'],
                    'empresa' => ['type' => 'string', 'title' => 'Empresa (company ID, if required)'],
                    'loginUrl' => [
                        'type' => 'string',
                        'title' => 'Login URL',
                        'default' => 'https://www.simplesagenda.com.br/'
                    ],
                    'usernameField' => ['type' => 'string', 'title' => 'Username field name', 'default' => 'login'],
                    'passwordField' => ['type' => 'string', 'title' => 'Password field name', 'default' => 'senha'],
                ],
                'required' => ['username', 'password'],
            ]),
            'uiConfig' => json_encode([
                'fields' => [
                    ['name' => 'username', 'type' => 'text', 'label' => 'Email'],
                    ['name' => 'password', 'type' => 'password', 'label' => 'Password'],
                    ['name' => 'empresa', 'type' => 'text', 'label' => 'Empresa (if required)'],
                    ['name' => 'loginUrl', 'type' => 'text', 'label' => 'Login URL', 'default' => 'https://www.simplesagenda.com.br/'],
                    ['name' => 'usernameField', 'type' => 'text', 'label' => 'Username field', 'default' => 'login'],
                    ['name' => 'passwordField', 'type' => 'text', 'label' => 'Password field', 'default' => 'senha'],
                ],
            ]),
            'encryptionFields' => json_encode(['password']),
            'requiresRotation' => true,
            'rotationDays' => 90,
            'isSystem' => true,
        ];

        $result = $this->seedCredentialType($config);
        $this->log->info("FeatureIntegrationSimplesAgenda: Credential type seeding completed ({$result})");
    }

    private function seedCredentialType(array $config): string
    {
        $code = $config['code'];

        $existing = $this->entityManager
            ->getRepository('CredentialType')
            ->where(['code' => $code])
            ->findOne();

        if ($existing) {
            if ($existing->get('isSystem')) {
                $existing->set([
                    'name' => $config['name'],
                    'category' => $config['category'],
                    'description' => $config['description'] ?? null,
                    'schema' => $config['schema'],
                    'uiConfig' => $config['uiConfig'] ?? null,
                    'encryptionFields' => $config['encryptionFields'] ?? '[]',
                    'requiresRotation' => $config['requiresRotation'] ?? false,
                    'rotationDays' => $config['rotationDays'] ?? 90,
                ]);
                $this->entityManager->saveEntity($existing);
                return 'updated';
            }
            return 'skipped';
        }

        $credentialType = $this->entityManager->getEntity('CredentialType');
        $credentialType->set([
            'name' => $config['name'],
            'code' => $config['code'],
            'category' => $config['category'],
            'description' => $config['description'] ?? null,
            'schema' => $config['schema'],
            'uiConfig' => $config['uiConfig'] ?? null,
            'encryptionFields' => $config['encryptionFields'] ?? '[]',
            'requiresRotation' => $config['requiresRotation'] ?? false,
            'rotationDays' => $config['rotationDays'] ?? 90,
            'isSystem' => $config['isSystem'] ?? false,
        ]);
        $this->entityManager->saveEntity($credentialType);
        return 'created';
    }
}

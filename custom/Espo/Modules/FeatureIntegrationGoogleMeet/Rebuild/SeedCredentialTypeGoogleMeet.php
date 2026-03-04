<?php
/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

namespace Espo\Modules\FeatureIntegrationGoogleMeet\Rebuild;

use Espo\Core\Rebuild\RebuildAction;
use Espo\Core\Utils\Log;
use Espo\ORM\EntityManager;

/**
 * Rebuild action to seed the Google Meet credential type.
 * Links to the msx_google_meet_01 OAuth provider and maps
 * OAuth tokens into the credential config automatically.
 */
class SeedCredentialTypeGoogleMeet implements RebuildAction
{
    private const CREDENTIAL_TYPE_ID = 'msx_ct_gmeet_01';
    private const OAUTH_PROVIDER_ID = 'msx_gmeet_01';

    public function __construct(
        private EntityManager $entityManager,
        private Log $log
    ) {}

    public function process(): void
    {
        $this->log->info('FeatureIntegrationGoogleMeet: Seeding credential type...');

        $config = [
            'name' => 'Google Meet',
            'code' => 'googleMeet',
            'category' => 'oauth2',
            'description' => 'Google Meet credential linking an OAuth Account to the Google Meet REST API v2. Access tokens are auto-managed via the linked OAuth Account.',
            'schema' => json_encode([
                'type' => 'object',
                'properties' => [
                    'accessToken' => ['type' => 'string', 'title' => 'Access Token', 'source' => 'oauth'],
                    'refreshToken' => ['type' => 'string', 'title' => 'Refresh Token', 'source' => 'oauth'],
                    'expiresAt' => ['type' => 'string', 'title' => 'Expires At', 'source' => 'oauth'],
                ],
                'required' => [],
            ]),
            'uiConfig' => json_encode([
                'fields' => [],
            ]),
            'tokenFieldMapping' => json_encode([
                'accessToken' => 'access_token',
                'refreshToken' => 'refresh_token',
                'expiresAt' => 'expires_at',
            ]),
            'encryptionFields' => json_encode([]),
            'requiresRotation' => false,
            'rotationDays' => 90,
            'isSystem' => true,
        ];

        $result = $this->seedCredentialType($config);
        $this->log->info("FeatureIntegrationGoogleMeet: Credential type seeding completed ({$result})");
    }

    private function seedCredentialType(array $config): string
    {
        $code = $config['code'];

        $existing = $this->entityManager
            ->getEntityById('CredentialType', self::CREDENTIAL_TYPE_ID);

        if ($existing) {
            $existing->set([
                'name' => $config['name'],
                'code' => $config['code'],
                'category' => $config['category'],
                'description' => $config['description'] ?? null,
                'schema' => $config['schema'],
                'uiConfig' => $config['uiConfig'] ?? null,
                'tokenFieldMapping' => $config['tokenFieldMapping'] ?? null,
                'encryptionFields' => $config['encryptionFields'] ?? '[]',
                'requiresRotation' => $config['requiresRotation'] ?? false,
                'rotationDays' => $config['rotationDays'] ?? 90,
                'isSystem' => true,
                'oAuthProviderId' => self::OAUTH_PROVIDER_ID,
            ]);
            $this->entityManager->saveEntity($existing);
            return 'updated';
        }

        $credentialType = $this->entityManager->getNewEntity('CredentialType');
        $credentialType->set([
            'id' => self::CREDENTIAL_TYPE_ID,
            'name' => $config['name'],
            'code' => $config['code'],
            'category' => $config['category'],
            'description' => $config['description'] ?? null,
            'schema' => $config['schema'],
            'uiConfig' => $config['uiConfig'] ?? null,
            'tokenFieldMapping' => $config['tokenFieldMapping'] ?? null,
            'encryptionFields' => $config['encryptionFields'] ?? '[]',
            'requiresRotation' => $config['requiresRotation'] ?? false,
            'rotationDays' => $config['rotationDays'] ?? 90,
            'isSystem' => true,
            'oAuthProviderId' => self::OAUTH_PROVIDER_ID,
        ]);
        $this->entityManager->saveEntity($credentialType);
        return 'created';
    }
}

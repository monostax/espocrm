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

namespace Espo\Modules\FeatureVoip\Rebuild;

use Espo\Core\Rebuild\RebuildAction;
use Espo\Core\Utils\Log;
use Espo\ORM\EntityManager;

/**
 * Rebuild action to seed the Twilio credential type.
 * Runs automatically during system rebuild.
 */
class SeedCredentialTypeTwilio implements RebuildAction
{
    public function __construct(
        private EntityManager $entityManager,
        private Log $log
    ) {}

    public function process(): void
    {
        $this->log->info('FeatureVoip: Seeding Twilio credential type...');

        $config = [
            'name' => 'Twilio',
            'code' => 'twilio',
            'category' => 'apiAuth',
            'description' => 'Twilio account credentials for VoIP calling (Account SID, API Key, Auth Token, From Number).',
            'schema' => json_encode([
                'type' => 'object',
                'properties' => [
                    'accountSid' => ['type' => 'string', 'title' => 'Account SID'],
                    'apiKeySid' => ['type' => 'string', 'title' => 'API Key SID'],
                    'apiKeySecret' => ['type' => 'string', 'title' => 'API Key Secret'],
                    'authToken' => ['type' => 'string', 'title' => 'Auth Token'],
                    'fromNumber' => ['type' => 'string', 'title' => 'From Number (E.164)'],
                ],
                'required' => ['accountSid', 'apiKeySid', 'apiKeySecret'],
            ]),
            'encryptionFields' => json_encode(['apiKeySecret', 'authToken']),
            'requiresRotation' => true,
            'rotationDays' => 365,
            'isSystem' => true,
        ];

        $result = $this->seedCredentialType($config);
        $this->log->info("FeatureVoip: Twilio credential type seeding completed ({$result})");
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
            'encryptionFields' => $config['encryptionFields'] ?? '[]',
            'requiresRotation' => $config['requiresRotation'] ?? false,
            'rotationDays' => $config['rotationDays'] ?? 90,
            'isSystem' => $config['isSystem'] ?? false,
        ]);
        $this->entityManager->saveEntity($credentialType);
        return 'created';
    }
}

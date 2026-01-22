<?php
/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 *
 * This software and associated documentation files (the "Software") are
 * the proprietary and confidential information of Monostax.
 *
 * Unauthorized copying, distribution, modification, public display, or use
 * of this Software, in whole or in part, via any medium, is strictly
 * prohibited without the express prior written permission of Monostax.
 *
 * This Software is licensed, not sold. Commercial use of this Software
 * requires a valid license from Monostax.
 *
 * For licensing information, please visit: https://www.monostax.ai
 ************************************************************************/

namespace Espo\Modules\Chatwoot\Rebuild;

use Espo\Core\ORM\Repository\Option\SaveOption;
use Espo\Core\Rebuild\RebuildAction;
use Espo\Core\Utils\Log;
use Espo\ORM\EntityManager;

/**
 * Rebuild action to seed the default ChatwootPlatform.
 * Creates or updates a "Default" ChatwootPlatform from environment variables.
 * Runs automatically during system rebuild.
 */
class SeedChatwootPlatform implements RebuildAction
{
    private const ENTITY_TYPE = 'ChatwootPlatform';
    private const DEFAULT_NAME = 'Default';

    public function __construct(
        private EntityManager $entityManager,
        private Log $log
    ) {}

    public function process(): void
    {
        $frontendUrl = getenv('CHATWOOT_PLATFORM_FRONTEND_URL');
        $backendUrl = getenv('CHATWOOT_PLATFORM_BACKEND_URL');
        $apiToken = getenv('CHATWOOT_PLATFORM_API_TOKEN');

        // Skip if environment variables are not configured
        if (!$frontendUrl || !$backendUrl || !$apiToken) {
            $this->log->debug(
                'SeedChatwootPlatform: Skipping - environment variables not configured ' .
                '(CHATWOOT_PLATFORM_FRONTEND_URL, CHATWOOT_PLATFORM_BACKEND_URL, CHATWOOT_PLATFORM_API_TOKEN)'
            );
            return;
        }

        $this->upsertPlatform($frontendUrl, $backendUrl, $apiToken);
    }

    private function upsertPlatform(string $frontendUrl, string $backendUrl, string $apiToken): void
    {
        $existing = $this->entityManager
            ->getRDBRepository(self::ENTITY_TYPE)
            ->where(['name' => self::DEFAULT_NAME])
            ->findOne();

        if ($existing) {
            $existing->set('frontendUrl', $frontendUrl);
            $existing->set('backendUrl', $backendUrl);
            $existing->set('accessToken', $apiToken);
            $existing->set('isDefault', true);

            $this->entityManager->saveEntity($existing, [SaveOption::SKIP_ALL => true]);

            $this->log->info("SeedChatwootPlatform: Updated default ChatwootPlatform");

            return;
        }

        $this->entityManager->createEntity(self::ENTITY_TYPE, [
            'name' => self::DEFAULT_NAME,
            'frontendUrl' => $frontendUrl,
            'backendUrl' => $backendUrl,
            'accessToken' => $apiToken,
            'isDefault' => true,
        ], [SaveOption::SKIP_ALL => true]);

        $this->log->info("SeedChatwootPlatform: Created default ChatwootPlatform");
    }
}

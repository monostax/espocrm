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
 * Rebuild action to seed the default WahaPlatform.
 * Creates or updates a "Default" WahaPlatform from environment variables.
 * Runs automatically during system rebuild.
 */
class SeedWahaPlatform implements RebuildAction
{
    private const ENTITY_TYPE = 'WahaPlatform';
    private const DEFAULT_NAME = 'Default';

    public function __construct(
        private EntityManager $entityManager,
        private Log $log
    ) {}

    public function process(): void
    {
        $frontendUrl = getenv('WAHA_PLATFORM_FRONTEND_URL');
        $backendUrl = getenv('WAHA_PLATFORM_BACKEND_URL');
        $apiKey = getenv('WAHA_PLATFORM_API_KEY');

        // Skip if environment variables are not configured
        if (!$frontendUrl || !$backendUrl || !$apiKey) {
            $this->log->debug(
                'SeedWahaPlatform: Skipping - environment variables not configured ' .
                '(WAHA_PLATFORM_FRONTEND_URL, WAHA_PLATFORM_BACKEND_URL, WAHA_PLATFORM_API_KEY)'
            );
            return;
        }

        $this->upsertPlatform($frontendUrl, $backendUrl, $apiKey);
    }

    private function upsertPlatform(string $frontendUrl, string $backendUrl, string $apiKey): void
    {
        $existing = $this->entityManager
            ->getRDBRepository(self::ENTITY_TYPE)
            ->where(['name' => self::DEFAULT_NAME])
            ->findOne();

        if ($existing) {
            $existing->set('frontendUrl', $frontendUrl);
            $existing->set('backendUrl', $backendUrl);
            $existing->set('apiKey', $apiKey);
            $existing->set('isDefault', true);

            $this->entityManager->saveEntity($existing, [SaveOption::SKIP_ALL => true]);

            $this->log->info("SeedWahaPlatform: Updated default WahaPlatform");

            return;
        }

        $this->entityManager->createEntity(self::ENTITY_TYPE, [
            'name' => self::DEFAULT_NAME,
            'frontendUrl' => $frontendUrl,
            'backendUrl' => $backendUrl,
            'apiKey' => $apiKey,
            'isDefault' => true,
        ], [SaveOption::SKIP_ALL => true]);

        $this->log->info("SeedWahaPlatform: Created default WahaPlatform");
    }
}

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

namespace Espo\Modules\Chatwoot\Hooks\Contact;

use Espo\Core\Hook\Hook\AfterSave;
use Espo\ORM\Repository\Option\SaveOptions;
use Espo\ORM\Entity;
use Espo\Core\Utils\Log;
use Espo\Core\Utils\Config;
use Espo\Modules\Chatwoot\Services\ContactSyncService;

/**
 * Hook to synchronize Contact with Chatwoot when created or updated.
 */
class SyncWithChatwoot implements AfterSave
{
    public static int $order = 20;

    public function __construct(
        private ContactSyncService $syncService,
        private Log $log,
        private Config $config
    ) {}

    /**
     * Sync contact to Chatwoot after entity is saved.
     */
    public function afterSave(Entity $entity, SaveOptions $options): void
    {
        // Skip if sync is disabled in config
        if (!$this->config->get('chatwootContactSyncEnabled', true)) {
            return;
        }

        // Skip if skipHooks option is set (to prevent infinite loops)
        if ($options->has('skipHooks') && $options->get('skipHooks')) {
            return;
        }

        // Skip if silent option is set
        if ($options->has('silent') && $options->get('silent')) {
            return;
        }

        try {
            // Check if relevant fields changed
            if (!$entity->isNew()) {
                $relevantFields = ['firstName', 'lastName', 'phoneNumber', 'emailAddress', 'phoneNumberData'];
                $hasChanges = false;
                
                foreach ($relevantFields as $field) {
                    if ($entity->isAttributeChanged($field)) {
                        $hasChanges = true;
                        break;
                    }
                }
                
                if (!$hasChanges) {
                    return;
                }
            }

            // Sync to Chatwoot
            $this->syncService->syncContactToChatwoot($entity);
        } catch (\Exception $e) {
            // Log the error but don't prevent the save
            $this->log->error(
                'Failed to sync Contact to Chatwoot (ID: ' . $entity->getId() . '): ' . 
                $e->getMessage()
            );
        }
    }
}


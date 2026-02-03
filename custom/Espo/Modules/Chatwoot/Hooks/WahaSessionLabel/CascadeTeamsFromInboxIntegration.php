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

namespace Espo\Modules\Chatwoot\Hooks\WahaSessionLabel;

use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Auto-cascades team from the parent ChatwootInboxIntegration to this entity.
 * Ensures proper multi-tenant ACL isolation.
 * Runs early (order=1) so team is set before validation hooks.
 */
class CascadeTeamsFromInboxIntegration
{
    public static int $order = 1;

    public function __construct(
        private EntityManager $entityManager
    ) {}

    /**
     * Cascade team from parent ChatwootInboxIntegration before save.
     * 
     * @param Entity $entity
     * @param array<string, mixed> $options
     */
    public function beforeSave(Entity $entity, array $options): void
    {
        // Skip for sync jobs - they handle team assignment directly
        if (!empty($options['silent'])) {
            return;
        }

        $inboxIntegrationId = $entity->get('inboxIntegrationId');
        if (!$inboxIntegrationId) {
            return;
        }

        $inboxIntegration = $this->entityManager->getEntityById('ChatwootInboxIntegration', $inboxIntegrationId);
        if (!$inboxIntegration) {
            return;
        }

        // Get team from the parent inbox integration
        $teamId = $inboxIntegration->get('teamId');
        if ($teamId) {
            $entity->set('teamId', $teamId);
        }
    }
}

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

namespace Espo\Modules\FeatureSelfService\Hooks\ChatwootInboxIntegration;

use Espo\Core\Utils\Log;
use Espo\Modules\FeatureSelfService\Tools\Workspace\WorkspaceTrial;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Throwable;

/**
 * A self-service workspace's first WhatsApp channel became ACTIVE: finish
 * onboarding and start its 14-day trial. Covers Meta coexistence activation
 * and QR inboxes adopted from Chatwoot.
 */
class StartTrial
{
    public static int $order = 50;

    public function __construct(
        private EntityManager $entityManager,
        private WorkspaceTrial $trial,
        private Log $log,
    ) {}

    /**
     * @param array<string, mixed> $options
     */
    public function afterSave(Entity $entity, array $options): void
    {
        if ($entity->get('status') !== 'ACTIVE' ||
            !WorkspaceTrial::isWhatsappChannel($entity->get('channelType')) ||
            (!$entity->isNew() && !$entity->isAttributeChanged('status'))) {
            return;
        }

        try {
            $account = $this->entityManager->getEntityById('ChatwootAccount', (string) $entity->get('chatwootAccountId'));
            $tenant = $account?->get('tenantId')
                ? $this->entityManager->getEntityById('Tenant', (string) $account->get('tenantId'))
                : null;

            if ($tenant && $this->trial->startIfConnected($tenant)) {
                $this->log->info("SelfService: trial started for tenant {$tenant->getId()} (first WhatsApp channel connected).");
            }
        } catch (Throwable $e) {
            // Never fail the channel save over the trial; the next status check retries.
            $this->log->error("SelfService: could not start the trial for integration {$entity->getId()}: " . $e->getMessage());
        }
    }
}

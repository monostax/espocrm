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

namespace Espo\Modules\Chatwoot\Hooks\ChatwootInboxIntegration;

use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\Core\Utils\Log;

/**
 * Auto-assigns the default WahaPlatform for QR code integrations.
 * Users don't need to manually select a WahaPlatform - it's handled automatically.
 * Runs early (order=2) so WahaPlatform is set before other hooks.
 */
class AssignDefaultWahaPlatform
{
    public static int $order = 2;

    public function __construct(
        private EntityManager $entityManager,
        private Log $log
    ) {}

    /**
     * Auto-assign default WahaPlatform for whatsappQrcode channel type.
     *
     * @param Entity $entity
     * @param array<string, mixed> $options
     */
    public function beforeSave(Entity $entity, array $options): void
    {
        // Skip if silent or if WahaPlatform is already set
        if (!empty($options['silent']) || $entity->get('wahaPlatformId')) {
            return;
        }

        // Only auto-assign for QR code integrations
        $channelType = $entity->get('channelType');
        if ($channelType !== 'whatsappQrcode') {
            return;
        }

        // Find the default WahaPlatform
        $defaultPlatform = $this->entityManager
            ->getRDBRepository('WahaPlatform')
            ->where(['isDefault' => true])
            ->findOne();

        if ($defaultPlatform) {
            $entity->set('wahaPlatformId', $defaultPlatform->getId());
            $this->log->debug("AssignDefaultWahaPlatform: Auto-assigned default WahaPlatform for ChatwootInboxIntegration");
        } else {
            $this->log->warning("AssignDefaultWahaPlatform: No default WahaPlatform found for QR code integration");
        }
    }
}

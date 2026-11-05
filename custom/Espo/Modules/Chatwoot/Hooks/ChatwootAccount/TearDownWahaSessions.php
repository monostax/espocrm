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

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Hooks\ChatwootAccount;

use Espo\Modules\Chatwoot\Tools\WahaSessionTeardown;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Removing a ChatwootAccount removes its channels as cascade children
 * (`cascadeParent`), and ChatwootInboxIntegration\CleanupOnRemove then
 * leaves WAHA alone. Deleting the Chatwoot account does not end WAHA
 * sessions either, so their WhatsApp numbers stayed logged in. End them
 * here, before CascadeDelete (order 5) and the remote delete (order 10).
 *
 * A session that can't be ended stops the removal, like a failed remote
 * account delete does.
 */
class TearDownWahaSessions
{
    public static int $order = 4;

    public function __construct(
        private EntityManager $entityManager,
        private WahaSessionTeardown $teardown,
    ) {}

    /**
     * @param array<string, mixed> $options
     */
    public function beforeRemove(Entity $entity, array $options): void
    {
        if (!empty($options['silent'])) {
            return;
        }

        $integrations = $this->entityManager
            ->getRDBRepository('ChatwootInboxIntegration')
            ->where(['chatwootAccountId' => $entity->getId()])
            ->find();

        foreach ($integrations as $integration) {
            $this->teardown->tearDown($integration);
        }
    }
}

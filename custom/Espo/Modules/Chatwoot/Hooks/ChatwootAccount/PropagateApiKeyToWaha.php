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

namespace Espo\Modules\Chatwoot\Hooks\ChatwootAccount;

use Espo\Core\Utils\Log;
use Espo\Modules\Chatwoot\Services\ChatwootWahaAppTokenSync;
use Espo\ORM\Entity;

/**
 * When a ChatwootAccount's `apiKey` (User Access Token) changes — whether
 * because an admin edited it in the UI, SyncWithChatwoot rebootstrapped
 * the concierge user, or any future automated rotation path wrote it —
 * fan the new value out to every WAHA Chatwoot app that was provisioned
 * for inboxes under this account.
 *
 * Without this hook, WAHA apps keep their snapshot of the old token in
 * `config.accountToken` and start returning
 *
 *     ChatWoot API Error: Unauthorized
 *     Status: 401 {"error":"Invalid Access Token"}
 *
 * on every outbound call from `ChatWootInboxCommandsConsumer`.
 *
 * Runs ONLY when `apiKey` actually changed on this save, and only after
 * the row has been persisted so the service reads the post-save value
 * from the same DB row.
 *
 * NOTE: rebuild actions that save the account with `SaveOption::SKIP_ALL`
 * (SeedChatwootAccount, RotateAutomationToConcierge) bypass this hook by
 * design. Those call paths invoke `ChatwootWahaAppTokenSync::syncForAccount`
 * directly — keeping the migration code explicit and the manual-edit path
 * hook-driven.
 */
class PropagateApiKeyToWaha
{
    /**
     * Run after the DB insert/update so the post-save state is visible
     * to the service, and after other afterSave hooks that might need
     * to read the account row (no strict dependency, but a late $order
     * means an apiKey-change-triggered resync won't collide with any
     * concurrent account-level bootstrap happening in earlier hooks).
     */
    public static int $order = 50;

    public function __construct(
        private ChatwootWahaAppTokenSync $tokenSync,
        private Log $log,
    ) {}

    public function afterSave(Entity $entity, array $options): void
    {
        // Never fan out during migrations, seeders, imports or any other
        // code path that opted out of hooks. The corresponding migration
        // step calls ChatwootWahaAppTokenSync directly.
        if (!empty($options['skipHooks']) || !empty($options['silent'])) {
            return;
        }

        // Nothing to do unless the token actually changed on this save.
        // isAttributeChanged() is the supported Espo API for this check.
        if (!$entity->isAttributeChanged('apiKey')) {
            return;
        }

        $newApiKey = $entity->get('apiKey');
        if (!$newApiKey) {
            // An apiKey clear is unusual but possible; WAHA apps will 401
            // on their own until someone populates the field again, so
            // there is no useful propagation to do here.
            return;
        }

        try {
            $this->tokenSync->syncForAccount($entity);
        } catch (\Throwable $e) {
            // Intentionally swallow: failing the outer save because a
            // downstream WAHA PUT misbehaved would make every token
            // rotation a two-step dance. The service already logs the
            // per-integration failures.
            $this->log->error(
                'PropagateApiKeyToWaha: Unexpected failure propagating apiKey for account ' .
                $entity->getId() . ' — ' . $e->getMessage()
            );
        }
    }
}

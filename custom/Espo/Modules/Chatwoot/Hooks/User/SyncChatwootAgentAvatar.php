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

namespace Espo\Modules\Chatwoot\Hooks\User;

use Espo\Core\Utils\Log;
use Espo\ORM\Entity;
use Espo\Modules\Chatwoot\Services\AgentAvatarSyncService;

/**
 * Mirror a CRM {@see \Espo\Entities\User}'s avatar change onto every Chatwoot
 * agent profile that points at the user.
 *
 * Fires on `User.afterSave` whenever `avatarId` is actually different from
 * the fetched value. The real work — resolving platform credentials,
 * deduping via content hash, calling `/api/v1/profile` — lives in
 * {@see AgentAvatarSyncService::pushCrmUserAvatarToChatwoot()}.
 *
 * ### Why afterSave (and not beforeSave)
 *
 * Chatwoot requires the attachment row to exist and be streamable on disk
 * before we can hand its bytes off. `beforeSave` runs while the entity is
 * still transient, so we'd be uploading stale bytes (or nothing) on a
 * fresh-avatar save.
 *
 * ### Why no guard for `skipHooks`
 *
 * The service itself is the sole writer that flips hash columns on
 * `ChatwootUser`, and it saves the CRM `User` with `['silent' => true]`
 * (no `skipHooks`). That re-entry is intentional: the hook fires, computes
 * the SHA-256 of the new attachment bytes, sees it already matches
 * `ChatwootUser.crmAvatarSyncHash`, and short-circuits. Adding a
 * `skipHooks` option here would couple the hook to the service's internal
 * save mechanics — the hash check is a cleaner contract.
 *
 * ### Failure policy
 *
 * Any exception out of the service is swallowed at the hook boundary. An
 * avatar sync problem must never prevent the user row itself from saving.
 */
class SyncChatwootAgentAvatar
{
    // After any User hooks that might normalise avatarId, but early enough
    // that we don't race with heavy post-save work elsewhere.
    public static int $order = 50;

    public function __construct(
        private AgentAvatarSyncService $agentAvatarSyncService,
        private Log $log
    ) {}

    /**
     * @param Entity $entity
     * @param array<string, mixed> $options
     */
    public function afterSave(Entity $entity, array $options): void
    {
        // Let integrations explicitly opt out — mostly a test seam, but also
        // useful when a migration bulk-resets avatars and does not want N
        // spurious Chatwoot pushes.
        if (!empty($options['skipChatwootAvatarSync'])) {
            return;
        }

        if (!$entity->isAttributeChanged('avatarId')) {
            return;
        }

        try {
            $this->agentAvatarSyncService->pushCrmUserAvatarToChatwoot($entity->getId());
        } catch (\Throwable $e) {
            $this->log->warning(
                'SyncChatwootAgentAvatar: Push to Chatwoot failed for User ' .
                $entity->getId() . ' — ' . $e->getMessage()
            );
        }
    }
}

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

namespace Espo\Modules\Chatwoot\Hooks\ChatwootAccountUserMembership;

use Espo\Core\Utils\Log;
use Espo\ORM\Entity;
use Espo\Modules\Chatwoot\Services\AgentAvatarSyncService;

/**
 * Mirror a Chatwoot agent's avatar onto the linked CRM {@see \Espo\Entities\User}
 * whenever the membership's `avatarUrl` is dirty.
 *
 * The `avatarUrl` column on `ChatwootAccountUserMembership` is the landing
 * spot for Chatwoot's agent original-blob URL (`avatar_original_url` /
 * `avatar_url`), falling back to `thumbnail` on legacy payloads — populated by
 * {@see \Espo\Modules\Chatwoot\Jobs\SyncAccountUserMembershipsFromChatwoot} and
 * {@see \Espo\Modules\Chatwoot\Services\ChatwootAccountUserMembershipService::populateMembershipFromAgentResponse()}.
 * This hook converts that URL change into bytes on the CRM User's
 * `avatarId`, giving the same image equal footing in the native Espo
 * avatar machinery (stream, mentions, list views) as Chatwoot.
 *
 * ### Why the hook fires on silent saves too
 *
 * The sync job and rebuild paths write `avatarUrl` with
 * `['silent' => true]`. Guarding on `!$options['silent']` the way our
 * sibling hooks do (see `DeleteFromChatwoot`, the membership
 * `SyncWithChatwoot`) would let the only reliable avatar update path slip
 * past us. Silent here only means "don't raise Stream/Notification noise",
 * not "this change is invisible to the domain".
 *
 * ### Loop prevention
 *
 * Deduplication is done downstream via the SHA-256 hash columns on
 * `ChatwootUser` ({@see AgentAvatarSyncService}). If the bytes behind the
 * new URL are the same ones we most recently pulled — which is the common
 * case when ActiveStorage rotates the variant URL without changing the
 * underlying blob — the service no-ops silently.
 */
class SyncChatwootAgentAvatar
{
    // After core sync hooks so those can finalise their fields first.
    public static int $order = 80;

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
        if (!empty($options['skipChatwootAvatarSync'])) {
            return;
        }

        if (!$entity->isAttributeChanged('avatarUrl')) {
            return;
        }

        $newAvatarUrl = $entity->get('avatarUrl');

        try {
            $this->agentAvatarSyncService->pullChatwootAvatarToCrmUser($entity, $newAvatarUrl);
        } catch (\Throwable $e) {
            $this->log->warning(
                'SyncChatwootAgentAvatar: Pull into CRM User failed for membership ' .
                $entity->getId() . ' — ' . $e->getMessage()
            );
        }
    }
}

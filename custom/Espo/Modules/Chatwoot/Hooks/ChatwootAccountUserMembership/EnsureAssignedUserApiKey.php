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
use Espo\Core\Utils\Util;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Auto-provision a dedicated {@see \Espo\Modules\Global\Entities\UserApiKey}
 * for the CRM user who powers an AI agent membership.
 *
 * ### Why
 *
 * The Chatwoot AI agent runtime needs a CRM API token to call back into
 * EspoCRM (calendar bookings, opportunity creation, etc.). Before this
 * hook, operators had to:
 *
 *   1. Open the assigned CRM user.
 *   2. Manually generate an API key.
 *   3. Hope they didn't forget to do it before the agent's first
 *      conversation kicked off.
 *
 * That manual step is now redundant. The moment a membership has
 * `isAI = true` and points at an active CRM user, this hook creates a
 * key (if one doesn't already exist) so the agent runtime always has
 * credentials ready.
 *
 * ### Trigger surface
 *
 * Runs on `afterSave` for both create and update paths — `silent` saves
 * included, because `ChatwootAccountUserMembershipService::enableAiProfile()`
 * persists with `silent=true` and that is the documented chokepoint for
 * flipping AI on. We MUST fire there or the feature never activates from
 * the UI.
 *
 * Idempotency is the safety net: the hook always queries the existing
 * `UserApiKey` rows for the assigned user, and only creates one when the
 * user has zero active, non-expired keys. Re-saves, sync loops, and
 * silent backfill all become no-ops after the first run.
 *
 * ### Edge cases
 *
 *   • `isAI = false` → do nothing.
 *   • Membership has no `chatwootUser` or that user has no
 *     `assignedUser` → log warn, skip. {@see ChatwootAccountUserMembershipService::enableAiProfile()}
 *     already validates assignedUser, but a direct entity save (bulk
 *     import, future migration) could bypass that.
 *   • Assigned CRM user is inactive → log warn, skip. The auth flow
 *     requires an active user anyway, so generating a key would just
 *     produce dead credentials.
 *   • An active key already exists → no-op. We never rotate or churn
 *     keys here — that's the operator's responsibility via the
 *     UserApiKey UI.
 *   • Creation itself throws → log error, swallow. The membership save
 *     has already succeeded by this point; we will not undo the user's
 *     work for an auxiliary provisioning failure. The operator can
 *     create the key manually if this hook ever errors.
 *
 * ### Ordering
 *
 * Runs after the core sync hooks (which decide the final `isAI` value
 * and finish writing to Chatwoot). Late ordering keeps this hook focused
 * purely on the post-commit "make sure credentials exist" concern.
 */
class EnsureAssignedUserApiKey
{
    // Run AFTER SyncWithChatwoot (order=10), SyncChatwootAgentAvatar
    // (order=80), and the other sync hooks. We want a stable, finalised
    // membership state before checking.
    public static int $order = 90;

    public function __construct(
        private EntityManager $entityManager,
        private Log $log,
    ) {}

    /**
     * @param Entity $entity
     * @param array<string, mixed> $options
     */
    public function afterSave(Entity $entity, array $options): void
    {
        if (!empty($options['skipAssignedUserApiKeyProvision'])) {
            return;
        }

        // Only act when AI is enabled on this membership.
        if (!$entity->get('isAI')) {
            return;
        }

        // On an update, only fire when isAI actually flipped. New
        // entities always run when isAI=true. This avoids re-checking on
        // every unrelated field edit, even though the inner query is
        // already idempotent.
        if (!$entity->isNew() && !$entity->isAttributeChanged('isAI')) {
            return;
        }

        $chatwootUserId = $entity->get('chatwootUserId');

        if (!$chatwootUserId) {
            $this->log->debug(
                'EnsureAssignedUserApiKey: membership ' . $entity->getId() .
                ' has isAI=true but no chatwootUserId; skipping.'
            );
            return;
        }

        $chatwootUser = $this->entityManager->getEntityById('ChatwootUser', $chatwootUserId);

        if (!$chatwootUser) {
            $this->log->warning(
                'EnsureAssignedUserApiKey: ChatwootUser ' . $chatwootUserId .
                ' not found for membership ' . $entity->getId() . '.'
            );
            return;
        }

        $assignedUserId = $chatwootUser->get('assignedUserId');

        if (!$assignedUserId) {
            $this->log->warning(
                'EnsureAssignedUserApiKey: ChatwootUser ' . $chatwootUserId .
                ' has no assignedUser; cannot provision UserApiKey for AI membership ' .
                $entity->getId() . '.'
            );
            return;
        }

        $crmUser = $this->entityManager->getEntityById('User', $assignedUserId);

        if (!$crmUser) {
            $this->log->warning(
                'EnsureAssignedUserApiKey: assigned CRM User ' . $assignedUserId .
                ' not found for membership ' . $entity->getId() . '.'
            );
            return;
        }

        if (!$crmUser->get('isActive')) {
            $this->log->warning(
                'EnsureAssignedUserApiKey: assigned CRM User ' . $assignedUserId .
                ' is inactive; skipping UserApiKey provisioning.'
            );
            return;
        }

        // Idempotency guard: do nothing if the user already has at least
        // one active, non-expired key. A direct WHERE query — instead of
        // walking the link collection — keeps this O(1) regardless of
        // historical/expired keys on the user.
        $existing = $this->entityManager
            ->getRDBRepository('UserApiKey')
            ->where([
                'userId' => $assignedUserId,
                'isActive' => true,
            ])
            ->where([
                'OR' => [
                    ['expiresAt' => null],
                    ['expiresAt>' => date('Y-m-d H:i:s')],
                ],
            ])
            ->findOne();

        if ($existing) {
            $this->log->debug(
                'EnsureAssignedUserApiKey: UserApiKey already present for CRM User ' .
                $assignedUserId . ' (key id ' . $existing->getId() .
                '); membership ' . $entity->getId() . ' is good to go.'
            );
            return;
        }

        // Build a friendly, recognisable name so the operator can spot
        // this key in the user's "API Keys" panel and know who
        // generated it.
        $name = $this->buildKeyName($entity);

        try {
            $apiKey = $this->entityManager->createEntity(
                'UserApiKey',
                [
                    'name' => $name,
                    'description' =>
                        'Auto-generated for Chat AI agent membership ' .
                        $entity->getId() . '. Safe to revoke or rename.',
                    'userId' => $assignedUserId,
                    'apiKey' => Util::generateApiKey(),
                    'isActive' => true,
                ],
                [
                    // Avoid stream/notification noise; the RecordHook on
                    // UserApiKey is also skipped, which is exactly what
                    // we want — we already produced the key value and
                    // we bypass the "non-admin can only create for self"
                    // guard because this is a system-initiated provision.
                    'silent' => true,
                ]
            );

            $this->log->info(
                'EnsureAssignedUserApiKey: provisioned UserApiKey ' . $apiKey->getId() .
                ' for CRM User ' . $assignedUserId . ' (Chatwoot AI membership ' .
                $entity->getId() . ').'
            );
        } catch (\Throwable $e) {
            // Never fail the membership save because of a provisioning
            // hiccup — the operator can create the key manually.
            $this->log->error(
                'EnsureAssignedUserApiKey: failed to create UserApiKey for CRM User ' .
                $assignedUserId . ' (membership ' . $entity->getId() . '): ' .
                $e->getMessage()
            );
        }
    }

    /**
     * Compose a human-friendly key name. Falls back gracefully when the
     * related account/user names aren't available.
     */
    private function buildKeyName(Entity $membership): string
    {
        $base = 'Chat AI Agent';

        $accountId = $membership->get('chatwootAccountId');

        if (!$accountId) {
            return $base;
        }

        $account = $this->entityManager->getEntityById('ChatwootAccount', $accountId);

        if (!$account) {
            return $base;
        }

        $accountName = trim((string) $account->get('name'));

        if ($accountName === '') {
            return $base;
        }

        return $base . ' — ' . $accountName;
    }
}

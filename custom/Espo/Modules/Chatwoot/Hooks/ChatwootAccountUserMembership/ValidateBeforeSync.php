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

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Error;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Validates that the ChatwootAccount, ChatwootPlatform, and ChatwootUser are configured
 * before creating a ChatwootAccountUserMembership from the UI.
 *
 * Separates validation from sync per codebase convention (Decision #14).
 * Runs BEFORE SyncWithChatwoot (order=10).
 *
 * Does NOT validate password — membership is for existing users (Decision #14).
 * Does NOT validate email/name — membership gets these from linked entities.
 */
class ValidateBeforeSync
{
    public static int $order = 9; // Run before SyncWithChatwoot at order=10

    public function __construct(
        private EntityManager $entityManager
    ) {}

    /**
     * Validate account, platform, and user are set and configured before save.
     *
     * @param Entity $entity
     * @param array<string, mixed> $options
     * @throws BadRequest
     * @throws Error
     */
    public function beforeSave(Entity $entity, array $options): void
    {
        // Skip validation if this is a silent save (from sync job)
        if (!empty($options['silent'])) {
            return;
        }

        // --- Validate ChatwootAccount chain ---

        $accountId = $entity->get('chatwootAccountId');
        if (!$accountId) {
            throw new BadRequest('Chat Account is required for Account User Membership.');
        }

        $account = $this->entityManager->getEntityById('ChatwootAccount', $accountId);
        if (!$account) {
            throw new Error('Selected Chat Account does not exist.');
        }

        $chatwootAccountId = $account->get('chatwootAccountId');
        if (!$chatwootAccountId) {
            throw new Error('Chat Account has not been synchronized with Chatwoot yet. Please wait for the account to be synced first.');
        }

        $platformId = $account->get('platformId');
        if (!$platformId) {
            throw new Error('Chat Account does not have a platform configured.');
        }

        $platform = $this->entityManager->getEntityById('ChatwootPlatform', $platformId);
        if (!$platform) {
            throw new Error('Chat Platform not found.');
        }

        $platformUrl = $platform->get('backendUrl');
        if (!$platformUrl) {
            throw new Error('Chat Platform does not have a URL configured.');
        }

        $accessToken = $platform->get('accessToken');
        if (!$accessToken) {
            throw new Error('Chat Platform does not have an access token configured.');
        }

        // --- Validate ChatwootUser chain ---

        $userId = $entity->get('chatwootUserId');
        if (!$userId) {
            throw new BadRequest('Chat User is required for Account User Membership.');
        }

        $user = $this->entityManager->getEntityById('ChatwootUser', $userId);
        if (!$user) {
            throw new Error('Selected Chat User does not exist.');
        }

        $externalUserId = $user->get('chatwootUserId');
        if (!$externalUserId) {
            throw new Error('Chat User has no external Chatwoot ID. The user may not have been synced to Chatwoot yet.');
        }

        // --- Role fallback ---

        if (!$entity->get('role')) {
            $entity->set('role', 'agent');
        }
    }
}

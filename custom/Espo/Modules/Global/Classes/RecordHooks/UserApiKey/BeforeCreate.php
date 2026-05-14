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

namespace Espo\Modules\Global\Classes\RecordHooks\UserApiKey;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Record\Hook\SaveHook;
use Espo\Core\Utils\Util;
use Espo\Entities\User;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Generate the API key for a UserApiKey record on creation and enforce
 * cross-user creation rules.
 *
 * Behavior:
 *   • If `userId` was omitted, default to the current user — the common
 *     case is "create a key for myself".
 *   • Non-admin callers may ONLY create keys for themselves. Attempts to
 *     forge a key against another user are rejected with `Forbidden`.
 *     (The access checker also denies edit on someone else's key, but
 *     create is special — the entity didn't exist yet, so the standard
 *     `checkEntityCreate` runs against the in-memory record. This hook
 *     adds the explicit identity guard.)
 *   • Generate a fresh `apiKey` value using `Util::generateApiKey()` (16
 *     hex chars from crypto-grade randomness, same primitive stock Espo
 *     uses for API-user keys). Clients must NEVER supply a value; any
 *     incoming value is overwritten.
 *   • Clear `lastUsedAt` — populated by the auth flow, not by callers.
 *
 * @implements SaveHook<Entity>
 * @noinspection PhpUnused
 */
class BeforeCreate implements SaveHook
{
    public function __construct(
        private EntityManager $entityManager,
        private User $currentUser,
    ) {}

    public function process(Entity $entity): void
    {
        $userId = $entity->get('userId');

        // Default: self. Admins are allowed to omit and target someone
        // else explicitly, but if neither is set we still bind to the
        // current user (typical "personal API key" workflow).
        if (!$userId) {
            $userId = $this->currentUser->getId();
            $entity->set('userId', $userId);
        }

        if (!$this->currentUser->isAdmin() && $userId !== $this->currentUser->getId()) {
            throw new Forbidden('UserApiKey: only admins can create keys for other users.');
        }

        $user = $this->entityManager->getEntityById('User', $userId);

        if (!$user) {
            throw new BadRequest('UserApiKey: user not found.');
        }

        if (!$user->get('isActive')) {
            throw new BadRequest('UserApiKey: user is not active.');
        }

        // Always regenerate on create — clients must never preset the
        // value. The unique index on `apiKey` further guards against
        // collisions on the rare hash duplicate (generateApiKey returns
        // 16 hex chars from crypto-grade randomness).
        $entity->set('apiKey', Util::generateApiKey());

        // `lastUsedAt` is filled by the auth flow; on create it must be
        // null even if a client tried to seed it.
        $entity->clear('lastUsedAt');
    }
}

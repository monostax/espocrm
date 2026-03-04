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

namespace Espo\Modules\FeatureOAuthEnhanced\Classes\RecordHooks\OAuthProvider;

use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Record\Hook\SaveHook;
use Espo\Entities\User;
use Espo\ORM\Entity;

/**
 * Defense-in-depth: prevents non-admin users from updating globally
 * shared OAuth providers or toggling the isGloballyShared flag.
 *
 * @implements SaveHook<Entity>
 * @noinspection PhpUnused
 */
class BeforeUpdate implements SaveHook
{
    public function __construct(
        private User $user,
    ) {}

    public function process(Entity $entity): void
    {
        if ($this->user->isAdmin()) {
            return;
        }

        if ($entity->getFetched('isGloballyShared')) {
            throw new Forbidden(
                "Globally shared OAuth providers can only be modified by administrators."
            );
        }

        if ($entity->isAttributeChanged('isGloballyShared')) {
            throw new Forbidden(
                "Only administrators can change the global sharing status of an OAuth provider."
            );
        }
    }
}

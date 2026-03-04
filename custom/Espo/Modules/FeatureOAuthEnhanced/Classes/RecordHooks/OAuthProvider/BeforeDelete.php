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
use Espo\Core\Record\Hook\DeleteHook;
use Espo\Core\Record\DeleteParams;
use Espo\Entities\User;
use Espo\ORM\Entity;

/**
 * Defense-in-depth: prevents non-admin users from deleting globally
 * shared OAuth providers.
 *
 * @implements DeleteHook<Entity>
 * @noinspection PhpUnused
 */
class BeforeDelete implements DeleteHook
{
    public function __construct(
        private User $user,
    ) {}

    public function process(Entity $entity, DeleteParams $params): void
    {
        if ($this->user->isAdmin()) {
            return;
        }

        if ($entity->get('isGloballyShared')) {
            throw new Forbidden(
                "Globally shared OAuth providers can only be deleted by administrators."
            );
        }
    }
}

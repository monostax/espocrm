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

namespace Espo\Modules\Global\Classes\Record\UserApiKey;

use Espo\Core\Record\Output\Filter;
use Espo\Entities\User;
use Espo\ORM\Entity;

/**
 * Output filter for UserApiKey records.
 *
 * Strips the raw `apiKey` value from output that does NOT belong to the
 * current user (and isn't being read by an admin). The whole entity is
 * already gated by the access checker — non-owners shouldn't see the row
 * at all — but field-level filtering is kept as a defence-in-depth in
 * case the entity is exposed through a relationship/list endpoint where
 * the row-level filter wouldn't apply.
 *
 * Practical effect:
 *   • Admins always see the plaintext key (operator recovery / handoff).
 *   • Owners always see the plaintext key for their own records.
 *   • Anyone else (in the rare case they reach this far) sees the key
 *     stripped to `null`.
 *
 * @implements Filter<Entity>
 */
class OutputFilter implements Filter
{
    public function __construct(
        private User $user,
    ) {}

    public function filter(Entity $entity): void
    {
        if ($this->user->isAdmin()) {
            return;
        }

        if ($entity->get('userId') === $this->user->getId()) {
            return;
        }

        $entity->clear('apiKey');
    }
}

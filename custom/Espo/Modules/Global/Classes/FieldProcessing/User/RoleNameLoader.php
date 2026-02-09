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

namespace Espo\Modules\Global\Classes\FieldProcessing\User;

use Espo\ORM\Entity;
use Espo\Core\FieldProcessing\Loader as LoaderInterface;
use Espo\Core\FieldProcessing\Loader\Params;
use Espo\Core\Di\LanguageAware;
use Espo\Core\Di\LanguageSetter;

/**
 * Translates Role names inside the User's "roles" linkMultiple field.
 *
 * The rolesNames attribute is a hash of {roleId => roleName}. This loader
 * replaces each raw role name (translation key) with its translated label
 * from the "roleNames" category in Role i18n files.
 *
 * @implements LoaderInterface<Entity>
 */
class RoleNameLoader implements LoaderInterface, LanguageAware
{
    use LanguageSetter;

    public function process(Entity $entity, Params $params): void
    {
        /** @var ?object $rolesNames */
        $rolesNames = $entity->get('rolesNames');

        if (!$rolesNames) {
            return;
        }

        $changed = false;

        foreach ($rolesNames as $id => $name) {
            if (!$name) {
                continue;
            }

            $translated = $this->language->translate($name, 'roleNames', 'Role');

            if ($translated !== $name) {
                $rolesNames->$id = $translated;
                $changed = true;
            }
        }

        if ($changed) {
            $entity->set('rolesNames', $rolesNames);
        }
    }
}

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

namespace Espo\Modules\Global\Classes\FieldProcessing\Role;

use Espo\ORM\Entity;
use Espo\Core\FieldProcessing\Loader as LoaderInterface;
use Espo\Core\FieldProcessing\Loader\Params;
use Espo\Core\Di\LanguageAware;
use Espo\Core\Di\LanguageSetter;

/**
 * Translates Role name on read using the current user's language.
 *
 * Role names are stored as translation keys (matching their staticId) in the
 * database. This loader resolves the stored key to a translated label via the
 * "roleNames" category in Role i18n files, falling back to the raw DB value
 * when no translation is found.
 *
 * @implements LoaderInterface<Entity>
 */
class NameLoader implements LoaderInterface, LanguageAware
{
    use LanguageSetter;

    public function process(Entity $entity, Params $params): void
    {
        $name = $entity->get('name');

        if (!$name) {
            return;
        }

        $translated = $this->language->translate($name, 'roleNames', 'Role');

        // When Language::translate() finds no match it returns the key unchanged.
        // In that case we keep the stored DB value as-is.
        if ($translated !== $name) {
            $entity->set('name', $translated);
        }
    }
}

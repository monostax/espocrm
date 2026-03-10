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

namespace Espo\Modules\FeatureClinica\Hooks\InsumoLote;

use Espo\ORM\Entity;

/**
 * On creation, initialises quantidadeAtual from quantidadeEntrada
 * so callers don't need to supply both values.
 */
class SetQuantidadeAtualOnCreate
{
    public static int $order = 1;

    public function beforeSave(Entity $entity, array $options): void
    {
        if (!$entity->isNew()) {
            return;
        }

        if ($entity->get('quantidadeAtual') !== null) {
            return;
        }

        $entity->set('quantidadeAtual', $entity->get('quantidadeEntrada'));
    }
}

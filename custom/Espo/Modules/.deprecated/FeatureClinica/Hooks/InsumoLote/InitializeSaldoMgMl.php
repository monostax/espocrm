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
 * On creation, initialises saldoMg/saldoMl for Medicamento/Cosmetico types.
 * Runs AFTER SetQuantidadeAtualOnCreate (order = 1) so quantidadeAtual is already set.
 */
class InitializeSaldoMgMl
{
    public static int $order = 2;

    public function beforeSave(Entity $entity, array $options): void
    {
        if (!$entity->isNew()) {
            return;
        }

        $insumoTipo = $entity->get('insumoTipo');

        if ($insumoTipo === 'Medicamento') {
            $dosagemTotal = $entity->get('insumoDosagemTotal');
            $volumeTotal = $entity->get('insumoVolumeTotal');

            if (empty($dosagemTotal) || $dosagemTotal <= 0) {
                // Log warning and skip initialization - data quality issue
                $GLOBALS['log']->warning(sprintf(
                    'InitializeSaldoMgMl: InsumoLote %s has Medicamento type but dosagemTotal is missing or zero. Skipping saldo initialization.',
                    $entity->getId()
                ));
                return;
            }

            if (empty($volumeTotal) || $volumeTotal <= 0) {
                $GLOBALS['log']->warning(sprintf(
                    'InitializeSaldoMgMl: InsumoLote %s has Medicamento type but volumeTotal is missing or zero. Skipping saldo initialization.',
                    $entity->getId()
                ));
                return;
            }

            $quantidadeAtual = $entity->get('quantidadeAtual');
            $entity->set('saldoMg', $quantidadeAtual * $dosagemTotal);
            $entity->set('saldoMl', $quantidadeAtual * $volumeTotal);
            $entity->set('statusUso', 'Nova');
        } elseif ($insumoTipo === 'Cosmetico') {
            $volumeTotal = $entity->get('insumoVolumeTotal');

            if (empty($volumeTotal) || $volumeTotal <= 0) {
                $GLOBALS['log']->warning(sprintf(
                    'InitializeSaldoMgMl: InsumoLote %s has Cosmetico type but volumeTotal is missing or zero. Skipping saldo initialization.',
                    $entity->getId()
                ));
                return;
            }

            $quantidadeAtual = $entity->get('quantidadeAtual');
            $entity->set('saldoMl', $quantidadeAtual * $volumeTotal);
            $entity->set('statusUso', 'Nova');
        }
    }
}
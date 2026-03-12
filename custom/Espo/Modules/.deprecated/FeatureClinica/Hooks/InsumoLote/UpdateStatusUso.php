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
 * Automatically updates statusUso based on saldo values.
 * Runs after saldo updates (order = 25).
 */
class UpdateStatusUso
{
    public static int $order = 25;

    public function afterSave(Entity $entity, array $options): void
    {
        $insumoTipo = $entity->get('insumoTipo');
        
        // Only process Medicamento and Cosmetico types
        if (!in_array($insumoTipo, ['Medicamento', 'Cosmetico'])) {
            return;
        }

        $saldoMg = $entity->get('saldoMg');
        $saldoMl = $entity->get('saldoMl');
        $sessoesUsadas = $entity->get('sessoesUsadas') ?? 0;
        $currentStatusUso = $entity->get('statusUso');

        $newStatusUso = null;
        $newStatus = null;

        // Determine new statusUso based on saldo
        if ($insumoTipo === 'Medicamento') {
            if ($saldoMg !== null && $saldoMg <= 0) {
                $newStatusUso = 'Esgotada';
                $newStatus = 'Esgotado';
            }
        }

        if ($saldoMl !== null && $saldoMl <= 0) {
            $newStatusUso = 'Esgotada';
            $newStatus = 'Esgotado';
        }

        // If not esgotada, check if em uso
        if ($newStatusUso === null) {
            if ($sessoesUsadas > 0) {
                $newStatusUso = 'EmUso';
            } elseif ($currentStatusUso !== 'Nova') {
                // Keep current if not Nova, otherwise set to Nova
                $newStatusUso = $currentStatusUso ?? 'Nova';
            }
        }

        // Update if changed
        $needsUpdate = false;
        if ($newStatusUso !== null && $newStatusUso !== $currentStatusUso) {
            $entity->set('statusUso', $newStatusUso);
            $needsUpdate = true;
        }
        if ($newStatus !== null && $newStatus !== $entity->get('status')) {
            $entity->set('status', $newStatus);
            $needsUpdate = true;
        }

        if ($needsUpdate) {
            $entity->save();
        }
    }
}
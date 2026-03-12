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

namespace Espo\Modules\FeatureClinica\Hooks\Sessao;

use Espo\Core\Exceptions\Error;
use Espo\ORM\Entity;

/**
 * Validates that exclusive lotes are only used in sessions for the correct patient (jornada).
 * Runs first (order = 1) before other hooks.
 */
class ValidateExclusiveLote
{
    public static int $order = 1;

    public function beforeSave(Entity $entity, array $options): void
    {
        $insumoLoteId = $entity->get('insumoLoteId');
        
        if (empty($insumoLoteId)) {
            return;
        }

        // Get the InsumoLote entity
        $entityManager = $GLOBALS['container']->get('entityManager');
        $insumoLote = $entityManager->getEntity('InsumoLote', $insumoLoteId);
        
        if (!$insumoLote) {
            return;
        }

        $modalidadeUso = $insumoLote->get('modalidadeUso');
        
        // Only validate exclusive lotes
        if ($modalidadeUso !== 'Exclusivo') {
            return;
        }

        $loteJornadaId = $insumoLote->get('jornadaId');
        $sessaoJornadaId = $entity->get('jornadaId');

        if (!empty($loteJornadaId) && $loteJornadaId !== $sessaoJornadaId) {
            throw new Error(
                'Este lote é exclusivo para outra jornada e não pode ser utilizado nesta sessão.'
            );
        }
    }
}
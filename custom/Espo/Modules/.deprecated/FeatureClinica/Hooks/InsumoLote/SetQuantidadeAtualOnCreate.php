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
 * On creation, initialises quantidadeAtual from quantidadeAquisicao
 * multiplied by quantidadePorUnidade from the related Insumo.
 *
 * quantidadeAquisicao is in acquisition units (e.g., caixas)
 * quantidadeAtual is in individual units (e.g., ampolas)
 *
 * Example: 10 caixas × 10 ampolas/caixa = 100 ampolas
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

        $quantidadeAquisicao = (float) $entity->get('quantidadeAquisicao');
        
        // Get quantidadePorUnidade from the related Insumo
        // This is a foreign field, so we need to get it from the insumo relation
        $insumo = $entity->get('insumo');
        $quantidadePorUnidade = 1;
        
        if ($insumo) {
            $qtd = $insumo->get('quantidadePorUnidade');
            if ($qtd && $qtd > 0) {
                $quantidadePorUnidade = (float) $qtd;
            }
        }
        
        // quantidadeAtual = quantidadeAquisicao × quantidadePorUnidade
        // E.g., 10 caixas × 10 ampolas/caixa = 100 ampolas
        $quantidadeAtual = $quantidadeAquisicao * $quantidadePorUnidade;
        
        $entity->set('quantidadeAtual', $quantidadeAtual);
    }
}

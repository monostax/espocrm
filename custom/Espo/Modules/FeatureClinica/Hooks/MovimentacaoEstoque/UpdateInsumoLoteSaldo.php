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

namespace Espo\Modules\FeatureClinica\Hooks\MovimentacaoEstoque;

use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Recalculates InsumoLote.quantidadeAtual and updates status
 * whenever a new MovimentacaoEstoque is created.
 */
class UpdateInsumoLoteSaldo
{
    public static int $order = 1;

    public function __construct(
        private EntityManager $entityManager
    ) {}

    public function afterSave(Entity $entity, array $options): void
    {
        if (!$entity->isNew()) {
            return;
        }

        $insumoLoteId = $entity->get('insumoLoteId');

        if (!$insumoLoteId) {
            return;
        }

        $insumoLote = $this->entityManager->getEntityById('InsumoLote', $insumoLoteId);

        if (!$insumoLote) {
            return;
        }

        $tipo = $entity->get('tipo');
        $quantidade = (float) $entity->get('quantidade');
        $quantidadeAtual = (float) $insumoLote->get('quantidadeAtual');

        switch ($tipo) {
            case 'Entrada':
                $quantidadeAtual += $quantidade;
                break;

            case 'Saida':
            case 'Descarte':
                $quantidadeAtual -= $quantidade;
                break;

            case 'Ajuste':
                $quantidadeAtual = $quantidade;
                break;
        }

        $insumoLote->set('quantidadeAtual', $quantidadeAtual);

        $previousStatus = $insumoLote->get('status');

        if ($quantidadeAtual <= 0) {
            $insumoLote->set('status', 'Esgotado');
        } elseif ($quantidadeAtual > 0 && $previousStatus === 'Esgotado') {
            $insumoLote->set('status', 'Disponivel');
        }

        $this->entityManager->saveEntity($insumoLote);
    }
}

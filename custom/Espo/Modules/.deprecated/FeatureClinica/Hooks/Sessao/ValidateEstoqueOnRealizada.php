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

use Espo\Core\Exceptions\BadRequest;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

class ValidateEstoqueOnRealizada
{
    public static int $order = 5;

    public function __construct(
        private EntityManager $entityManager
    ) {}

    /**
     * @throws BadRequest
     */
    public function beforeSave(Entity $entity, array $options): void
    {
        if (!$entity->isAttributeChanged('status')) {
            return;
        }

        if ($entity->get('status') !== 'Realizada') {
            return;
        }

        $procedimentoType = $entity->get('procedimentoType');
        $procedimentoId = $entity->get('procedimentoId');

        if (!$procedimentoType || !$procedimentoId) {
            return;
        }

        $isStockControlled = false;

        if ($procedimentoType === 'ProcedimentoInjetavel') {
            $procedimento = $this->entityManager->getEntityById('ProcedimentoInjetavel', $procedimentoId);
            if ($procedimento && $procedimento->get('controlaEstoque')) {
                $isStockControlled = true;
            }
        } elseif ($procedimentoType === 'ProcedimentoImplante') {
            $isStockControlled = true;
        }

        if (!$isStockControlled) {
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

        $quantidadeAtual = (float) $insumoLote->get('quantidadeAtual');
        $necessario = 1.0;
        $unidade = '';

        $insumoId = $insumoLote->get('insumoId');

        if ($insumoId) {
            $insumo = $this->entityManager->getEntityById('Insumo', $insumoId);

            if ($insumo) {
                // Use apresentacao for stock display unit (deprecated unidadeMedida)
                $apresentacao = $insumo->get('apresentacao');
                $unidade = $apresentacao ? " {$apresentacao}" : '';

                // Use unidadeDosagem for clinical dosing checks
                $unidadeDosagem = $insumo->get('unidadeDosagem');
                if ($unidadeDosagem) {
                    $dosagemAplicada = (float) $entity->get('dosagemAplicada');
                    if ($dosagemAplicada > 0) {
                        $necessario = $dosagemAplicada;
                    }
                }
            }
        }

        if ($necessario > $quantidadeAtual) {
            $dispStr = number_format($quantidadeAtual, 2, ',', '.');
            $necStr = number_format($necessario, 2, ',', '.');

            throw new BadRequest(
                "Estoque insuficiente no lote selecionado. Disponível: {$dispStr}{$unidade}, necessário: {$necStr}{$unidade}."
            );
        }
    }
}

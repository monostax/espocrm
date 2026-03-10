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

namespace Espo\Modules\FeatureClinica\Hooks\ProcedimentoRealizado;

use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Auto-creates a MovimentacaoEstoque (Saida) when a ProcedimentoRealizado
 * is created for a stock-controlled procedure with an insumoLoteId set.
 */
class CreateMovimentacaoEstoque
{
    public static int $order = 11;

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

        $procedimentoType = $entity->get('procedimentoType');

        if ($procedimentoType === 'ProcedimentoInjetavel') {
            $procedimentoId = $entity->get('procedimentoId');

            if (!$procedimentoId) {
                return;
            }

            $procedimento = $this->entityManager->getEntityById('ProcedimentoInjetavel', $procedimentoId);

            if (!$procedimento || !$procedimento->get('controlaEstoque')) {
                return;
            }
        } elseif ($procedimentoType !== 'ProcedimentoImplante') {
            return;
        }

        $atendimentoId = $entity->get('atendimentoId');

        if (!$atendimentoId) {
            return;
        }

        $atendimento = $this->entityManager->getEntityById('Atendimento', $atendimentoId);

        if (!$atendimento) {
            return;
        }

        $quantidade = $entity->get('quantidade') ?? 1;
        $teamsIds = $entity->getLinkMultipleIdList('teams');

        $movimentacao = $this->entityManager->getNewEntity('MovimentacaoEstoque');
        $movimentacao->set([
            'insumoLoteId' => $insumoLoteId,
            'unidadeId' => $atendimento->get('unidadeId'),
            'tipo' => 'Saida',
            'quantidade' => $quantidade,
            'origemType' => 'ProcedimentoRealizado',
            'origemId' => $entity->getId(),
            'profissionalId' => $atendimento->get('profissionalId'),
            'dataHora' => date('Y-m-d H:i:s'),
        ]);

        if (!empty($teamsIds)) {
            $movimentacao->set('teamsIds', $teamsIds);
        }

        $this->entityManager->saveEntity($movimentacao);
    }
}

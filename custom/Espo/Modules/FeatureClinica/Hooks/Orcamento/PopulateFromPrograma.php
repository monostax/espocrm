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

namespace Espo\Modules\FeatureClinica\Hooks\Orcamento;

use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * When a new Orcamento is saved with a programaId, auto-generates
 * OrcamentoItem records from the Programa's ProgramaItems, using
 * the pre-calculated prices stored on ProgramaItem (with fallback
 * to TabelaDePrecos lookup if the item has no price).
 */
class PopulateFromPrograma
{
    public static int $order = 5;

    public function __construct(
        private EntityManager $entityManager
    ) {}

    public function afterSave(Entity $entity, array $options): void
    {
        if (!$entity->isNew()) {
            return;
        }

        $programaId = $entity->get('programaId');

        if (!$programaId) {
            return;
        }

        $programa = $this->entityManager->getEntityById('Programa', $programaId);

        if (!$programa) {
            return;
        }

        $teamsIds = $entity->getLinkMultipleIdList('teams');
        $unidadeId = $entity->get('unidadeId');
        $today = date('Y-m-d');

        $itens = $this->entityManager
            ->getRDBRepository('ProgramaItem')
            ->where(['programaId' => $programaId])
            ->order('ordem', 'ASC')
            ->find();

        $sumTotal = 0.0;

        foreach ($itens as $item) {
            $quantidade = (int) $item->get('quantidade');
            if ($quantidade < 1) {
                $quantidade = 1;
            }

            $procedimentoType = $item->get('procedimentoType');
            $procedimentoId = $item->get('procedimentoId');

            $valorUnitario = (float) $item->get('valorUnitario');

            if ($valorUnitario <= 0) {
                $valorUnitario = $this->findPrice($procedimentoType, $procedimentoId, $unidadeId, $today);
            }

            $valorTotal = $valorUnitario * $quantidade;
            $sumTotal += $valorTotal;

            $orcamentoItem = $this->entityManager->getNewEntity('OrcamentoItem');
            $orcamentoItem->set([
                'orcamentoId' => $entity->getId(),
                'procedimentoType' => $procedimentoType,
                'procedimentoId' => $procedimentoId,
                'quantidade' => $quantidade,
                'valorUnitario' => $valorUnitario,
                'valorTotal' => $valorTotal,
                'valorComDesconto' => $valorTotal,
                'observacao' => $item->get('observacao'),
            ]);

            if (!empty($teamsIds)) {
                $orcamentoItem->set('teamsIds', $teamsIds);
            }

            $this->entityManager->saveEntity($orcamentoItem, ['skipRecalc' => true]);
        }

        $this->recalcOrcamentoTotals($entity, $sumTotal);
    }

    private function recalcOrcamentoTotals(Entity $orcamento, float $sumTotal): void
    {
        $valorDesconto = (float) $orcamento->get('valorDesconto');

        $orcamento->set('valorTotal', $sumTotal);
        $orcamento->set('valorLiquido', $sumTotal - $valorDesconto);

        $this->entityManager->saveEntity($orcamento, ['skipHooks' => true]);
    }

    private function findPrice(
        ?string $procedimentoType,
        ?string $procedimentoId,
        ?string $unidadeId,
        string $today
    ): float {
        if (!$procedimentoType || !$procedimentoId) {
            return 0.0;
        }

        $where = [
            'procedimentoType' => $procedimentoType,
            'procedimentoId' => $procedimentoId,
            'ativo' => true,
            'vigenciaInicio<=' => $today,
            'OR' => [
                ['vigenciaFim>=' => $today],
                ['vigenciaFim' => null],
            ],
        ];

        if ($unidadeId) {
            $where['unidadeId'] = $unidadeId;
        }

        $tabelaPreco = $this->entityManager
            ->getRDBRepository('TabelaDePrecos')
            ->where($where)
            ->order('vigenciaInicio', 'DESC')
            ->findOne();

        if ($tabelaPreco) {
            return (float) $tabelaPreco->get('valor');
        }

        if ($unidadeId) {
            unset($where['unidadeId']);

            $tabelaPreco = $this->entityManager
                ->getRDBRepository('TabelaDePrecos')
                ->where($where)
                ->order('vigenciaInicio', 'DESC')
                ->findOne();

            if ($tabelaPreco) {
                return (float) $tabelaPreco->get('valor');
            }
        }

        return 0.0;
    }
}

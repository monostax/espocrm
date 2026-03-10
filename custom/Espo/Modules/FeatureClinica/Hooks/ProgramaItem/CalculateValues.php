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

namespace Espo\Modules\FeatureClinica\Hooks\ProgramaItem;

use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Auto-fills valorUnitario from TabelaDePrecos when a procedimento is
 * selected and recalculates valorTotal = quantidade * valorUnitario.
 */
class CalculateValues
{
    public static int $order = 5;

    public function __construct(
        private EntityManager $entityManager
    ) {}

    public function beforeSave(Entity $entity, array $options): void
    {
        $this->autoFillPrice($entity);
        $this->calculateTotal($entity);
    }

    private function autoFillPrice(Entity $entity): void
    {
        if (!$entity->isAttributeChanged('procedimentoId')) {
            return;
        }

        $valorUnitario = (float) $entity->get('valorUnitario');
        if ($valorUnitario > 0) {
            return;
        }

        $procedimentoType = $entity->get('procedimentoType');
        $procedimentoId = $entity->get('procedimentoId');

        if (!$procedimentoType || !$procedimentoId) {
            return;
        }

        $unidadeId = $this->getUnidadeId($entity);
        $today = date('Y-m-d');

        $price = $this->findPrice($procedimentoType, $procedimentoId, $unidadeId, $today);

        if ($price > 0) {
            $entity->set('valorUnitario', $price);
        }
    }

    private function calculateTotal(Entity $entity): void
    {
        $quantidade = (int) $entity->get('quantidade');
        if ($quantidade < 1) {
            $quantidade = 1;
        }

        $valorUnitario = (float) $entity->get('valorUnitario');
        $entity->set('valorTotal', $valorUnitario * $quantidade);
    }

    private function getUnidadeId(Entity $entity): ?string
    {
        $programaId = $entity->get('programaId');
        if (!$programaId) {
            return null;
        }

        $programa = $this->entityManager->getEntityById('Programa', $programaId);
        if (!$programa) {
            return null;
        }

        return $programa->get('unidadeId');
    }

    private function findPrice(
        string $procedimentoType,
        string $procedimentoId,
        ?string $unidadeId,
        string $today
    ): float {
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

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

namespace Espo\Modules\FeatureClinica\Hooks\OrcamentoItem;

use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * After an OrcamentoItem is saved or removed, recalculates the parent
 * Orcamento's valorTotal (sum of items) and valorLiquido.
 */
class RecalcOrcamentoTotals
{
    public static int $order = 10;

    public function __construct(
        private EntityManager $entityManager
    ) {}

    public function afterSave(Entity $entity, array $options): void
    {
        if (!empty($options['skipRecalc'])) {
            return;
        }

        $this->recalculate($entity->get('orcamentoId'));
    }

    public function afterRemove(Entity $entity, array $options): void
    {
        $this->recalculate($entity->get('orcamentoId'));
    }

    private function recalculate(?string $orcamentoId): void
    {
        if (!$orcamentoId) {
            return;
        }

        $orcamento = $this->entityManager->getEntityById('Orcamento', $orcamentoId);

        if (!$orcamento) {
            return;
        }

        $itens = $this->entityManager
            ->getRDBRepository('OrcamentoItem')
            ->where(['orcamentoId' => $orcamentoId])
            ->find();

        $sum = 0.0;

        foreach ($itens as $item) {
            $valorComDesconto = (float) $item->get('valorComDesconto');
            $valorTotal = (float) $item->get('valorTotal');

            $sum += ($valorComDesconto > 0) ? $valorComDesconto : $valorTotal;
        }

        $orcamento->set('valorTotal', $sum);

        $valorDesconto = (float) $orcamento->get('valorDesconto');
        $orcamento->set('valorLiquido', $sum - $valorDesconto);

        $this->entityManager->saveEntity($orcamento);
    }
}

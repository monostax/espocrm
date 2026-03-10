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
 * After a ProgramaItem is saved or removed, recalculates the parent
 * Programa's precoTotal as the sum of all items' valorTotal.
 */
class RecalcProgramaTotal
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

        $this->recalculate($entity->get('programaId'));
    }

    public function afterRemove(Entity $entity, array $options): void
    {
        $this->recalculate($entity->get('programaId'));
    }

    private function recalculate(?string $programaId): void
    {
        if (!$programaId) {
            return;
        }

        $programa = $this->entityManager->getEntityById('Programa', $programaId);

        if (!$programa) {
            return;
        }

        $itens = $this->entityManager
            ->getRDBRepository('ProgramaItem')
            ->where(['programaId' => $programaId])
            ->find();

        $sum = 0.0;

        foreach ($itens as $item) {
            $sum += (float) $item->get('valorTotal');
        }

        $programa->set('precoTotal', $sum);

        $this->entityManager->saveEntity($programa, ['skipHooks' => true]);
    }
}

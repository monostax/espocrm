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

namespace Espo\Modules\FeatureClinica\Hooks\TabelaDePrecos;

use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

class ExpirePreviousVigencia
{
    public static int $order = 9;

    public function __construct(
        private EntityManager $entityManager
    ) {}

    public function beforeSave(Entity $entity, array $options): void
    {
        if (!$entity->isNew()) {
            return;
        }

        $procedimentoType = $entity->get('procedimentoType');
        $procedimentoId = $entity->get('procedimentoId');
        $vigenciaInicio = $entity->get('vigenciaInicio');

        if (!$procedimentoType || !$procedimentoId || !$vigenciaInicio) {
            return;
        }

        $where = [
            'procedimentoType' => $procedimentoType,
            'procedimentoId' => $procedimentoId,
            'ativo' => true,
            'vigenciaFim' => null,
        ];

        $unidadeId = $entity->get('unidadeId');
        if ($unidadeId) {
            $where['unidadeId'] = $unidadeId;
        } else {
            $where['unidadeId'] = null;
        }

        $previous = $this->entityManager
            ->getRDBRepository('TabelaDePrecos')
            ->where($where)
            ->findOne();

        if (!$previous) {
            return;
        }

        $expireDate = (new \DateTime($vigenciaInicio))
            ->modify('-1 day')
            ->format('Y-m-d');

        $previous->set('vigenciaFim', $expireDate);
        $this->entityManager->saveEntity($previous, ['silent' => true]);
    }
}

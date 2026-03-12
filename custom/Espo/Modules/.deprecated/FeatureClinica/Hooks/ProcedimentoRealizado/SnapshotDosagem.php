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

class SnapshotDosagem
{
    public static int $order = 9;

    public function __construct(
        private EntityManager $entityManager
    ) {}

    public function beforeSave(Entity $entity, array $options): void
    {
        $sessaoId = $entity->get('sessaoId');
        if (!$sessaoId) {
            return;
        }

        if (!$entity->isNew() && !$entity->isAttributeChanged('sessaoId')) {
            return;
        }

        $sessao = $this->entityManager->getEntityById('Sessao', $sessaoId);
        if (!$sessao) {
            return;
        }

        $entity->set('dosagemAplicada', $sessao->get('dosagemAplicada'));
        $entity->set('unidadeDosagemId', $sessao->get('unidadeDosagemId'));

        if (!$entity->get('insumoLoteId') && $sessao->get('insumoLoteId')) {
            $entity->set('insumoLoteId', $sessao->get('insumoLoteId'));
        }
    }
}

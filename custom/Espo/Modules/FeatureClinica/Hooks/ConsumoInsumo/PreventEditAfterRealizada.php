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

namespace Espo\Modules\FeatureClinica\Hooks\ConsumoInsumo;

use Espo\Core\Exceptions\Forbidden;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Prevents editing or deleting ConsumoInsumo records when the parent
 * Sessao has already been realized (stock was already reduced).
 */
class PreventEditAfterRealizada
{
    public static int $order = 1;

    public function __construct(
        private EntityManager $entityManager
    ) {}

    /**
     * @throws Forbidden
     */
    public function beforeSave(Entity $entity, array $options): void
    {
        if ($entity->isNew()) {
            return;
        }

        $this->checkSessaoStatus($entity);
    }

    /**
     * @throws Forbidden
     */
    public function beforeRemove(Entity $entity, array $options): void
    {
        $this->checkSessaoStatus($entity);
    }

    /**
     * @throws Forbidden
     */
    private function checkSessaoStatus(Entity $entity): void
    {
        $sessaoId = $entity->get('sessaoId');

        if (!$sessaoId) {
            return;
        }

        $sessao = $this->entityManager->getEntityById('Sessao', $sessaoId);

        if (!$sessao) {
            return;
        }

        if ($sessao->get('status') === 'Realizada') {
            throw new Forbidden(
                "Não é possível alterar consumos de insumo após a sessão ser realizada."
            );
        }
    }
}

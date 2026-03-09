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

namespace Espo\Modules\FeatureClinica\Hooks\Paciente;

use Espo\Core\Exceptions\Conflict;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

class ValidateUniqueCpf
{
    public static int $order = 9;

    public function __construct(
        private EntityManager $entityManager
    ) {}

    /**
     * @throws Conflict
     */
    public function beforeSave(Entity $entity, array $options): void
    {
        $cpf = $entity->get('cpf');
        if (!$cpf) {
            return;
        }

        if (!$entity->isNew() && !$entity->isAttributeChanged('cpf')) {
            return;
        }

        $where = ['cpf' => $cpf];

        if (!$entity->isNew()) {
            $where['id!='] = $entity->getId();
        }

        $existing = $this->entityManager
            ->getRDBRepository('Paciente')
            ->where($where)
            ->findOne();

        if ($existing) {
            throw new Conflict('CPF já cadastrado para outro paciente.');
        }
    }
}

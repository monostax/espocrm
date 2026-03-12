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

namespace Espo\Modules\FeatureClinica\Hooks\Appointment;

use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Auto-creates an Atendimento when Appointment status changes to "Realized".
 * Implemented as beforeSave to ensure atomicity: if Atendimento creation
 * fails, the Appointment status change is rolled back.
 */
class CreateAtendimentoOnRealizado
{
    public static int $order = 9;

    public function __construct(
        private EntityManager $entityManager
    ) {}

    public function beforeSave(Entity $entity, array $options): void
    {
        if (!$entity->isAttributeChanged('status')) {
            return;
        }

        if ($entity->get('status') !== 'Realized') {
            return;
        }

        if ($entity->get('atendimentoId')) {
            return;
        }

        $pacienteId = $entity->get('pacienteId');
        $unidadeId = $entity->get('unidadeId');
        $profissionalId = $entity->get('profissionalId');

        if (!$pacienteId || !$unidadeId || !$profissionalId) {
            return;
        }

        $teamsIds = $entity->getLinkMultipleIdList('teams');

        $atendimento = $this->entityManager->getNewEntity('Atendimento');
        $atendimento->set([
            'pacienteId' => $pacienteId,
            'unidadeId' => $unidadeId,
            'profissionalId' => $profissionalId,
            'appointmentId' => $entity->getId(),
            'dataHoraInicio' => $entity->get('dateStart'),
            'status' => 'EmAndamento',
        ]);

        if (!empty($teamsIds)) {
            $atendimento->set('teamsIds', $teamsIds);
        }

        $this->entityManager->saveEntity($atendimento);

        $entity->set('atendimentoId', $atendimento->getId());
    }
}

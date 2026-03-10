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

use Espo\Core\Exceptions\BadRequest;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

class ValidatePrescricao
{
    public static int $order = 10;

    public function __construct(
        private EntityManager $entityManager
    ) {}

    /**
     * @throws BadRequest
     */
    public function beforeSave(Entity $entity, array $options): void
    {
        if ($entity->get('status') === 'Canceled') {
            return;
        }

        $procedimentoType = $entity->get('procedimentoType');
        if ($procedimentoType !== 'ProcedimentoInjetavel') {
            return;
        }

        $procedimentoId = $entity->get('procedimentoId');
        if (!$procedimentoId) {
            return;
        }

        $procedimento = $this->entityManager->getEntityById('ProcedimentoInjetavel', $procedimentoId);
        if (!$procedimento) {
            return;
        }

        if (!$procedimento->get('requerPrescricao')) {
            return;
        }

        $pacienteId = $entity->get('pacienteId');
        if (!$pacienteId) {
            return;
        }

        $today = date('Y-m-d');

        $prescricao = $this->entityManager
            ->getRDBRepository('Prescricao')
            ->where([
                'pacienteId' => $pacienteId,
                'status' => 'Ativa',
                'dataValidade>=' => $today,
            ])
            ->findOne();

        if (!$prescricao) {
            throw new BadRequest(
                'Paciente não possui prescrição ativa para este procedimento injetável.'
            );
        }

        $prescricaoItem = $this->entityManager
            ->getRDBRepository('PrescricaoItem')
            ->where([
                'prescricaoId' => $prescricao->getId(),
                'procedimentoType' => 'ProcedimentoInjetavel',
                'procedimentoId' => $procedimentoId,
            ])
            ->findOne();

        if (!$prescricaoItem) {
            throw new BadRequest(
                'Paciente não possui prescrição ativa para este procedimento injetável.'
            );
        }
    }
}

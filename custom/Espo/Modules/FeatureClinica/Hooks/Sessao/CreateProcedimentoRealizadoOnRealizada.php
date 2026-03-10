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

namespace Espo\Modules\FeatureClinica\Hooks\Sessao;

use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

class CreateProcedimentoRealizadoOnRealizada
{
    public static int $order = 10;

    public function __construct(
        private EntityManager $entityManager
    ) {}

    public function afterSave(Entity $entity, array $options): void
    {
        if (!$entity->isAttributeChanged('status')) {
            return;
        }

        if ($entity->get('status') !== 'Realizada') {
            return;
        }

        $procedimentoType = $entity->get('procedimentoType');
        $procedimentoId = $entity->get('procedimentoId');

        if (!$procedimentoType || !$procedimentoId) {
            return;
        }

        $isStockControlled = false;

        if ($procedimentoType === 'ProcedimentoInjetavel') {
            $procedimento = $this->entityManager->getEntityById('ProcedimentoInjetavel', $procedimentoId);
            if ($procedimento && $procedimento->get('controlaEstoque')) {
                $isStockControlled = true;
            }
        } elseif ($procedimentoType === 'ProcedimentoImplante') {
            $isStockControlled = true;
        }

        if (!$isStockControlled) {
            return;
        }

        $existing = $this->entityManager
            ->getRDBRepository('ProcedimentoRealizado')
            ->where([
                'sessaoId' => $entity->getId(),
                'deleted' => false,
            ])
            ->findOne();

        if ($existing) {
            return;
        }

        $atendimentoId = null;
        $appointmentId = $entity->get('appointmentId');

        if ($appointmentId) {
            $appointment = $this->entityManager->getEntityById('Appointment', $appointmentId);
            if ($appointment) {
                $atendimentoId = $appointment->get('atendimentoId');
            }
        }

        $pr = $this->entityManager->getNewEntity('ProcedimentoRealizado');
        $pr->set([
            'sessaoId' => $entity->getId(),
            'procedimentoType' => $procedimentoType,
            'procedimentoId' => $procedimentoId,
            'insumoLoteId' => $entity->get('insumoLoteId'),
            'dosagemAplicada' => $entity->get('dosagemAplicada'),
            'unidadeDosagemId' => $entity->get('unidadeDosagemId'),
            'quantidade' => 1,
        ]);

        if ($atendimentoId) {
            $pr->set('atendimentoId', $atendimentoId);
        }

        $teamsIds = $entity->getLinkMultipleIdList('teams');
        if (!empty($teamsIds)) {
            $pr->set('teamsIds', $teamsIds);
        }

        $this->entityManager->saveEntity($pr);
    }
}

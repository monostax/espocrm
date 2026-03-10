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

/**
 * Validates no time overlap for the same profissional on the same date/time.
 */
class CheckScheduleConflict
{
    public static int $order = 8;

    public function __construct(
        private EntityManager $entityManager
    ) {}

    /**
     * @throws BadRequest
     */
    public function beforeSave(Entity $entity, array $options): void
    {
        $status = $entity->get('status');

        if ($status === 'Canceled' || $status === 'NoShow') {
            return;
        }

        $profissionalId = $entity->get('profissionalId');
        $dateStart = $entity->get('dateStart');

        if (!$profissionalId || !$dateStart) {
            return;
        }

        $isRelevantChange = $entity->isNew()
            || $entity->isAttributeChanged('dateStart')
            || $entity->isAttributeChanged('dateEnd')
            || $entity->isAttributeChanged('profissionalId');

        if (!$isRelevantChange) {
            return;
        }

        $dateEnd = $entity->get('dateEnd');

        if (!$dateEnd) {
            $duracaoMin = (int) $entity->get('duracaoPrevistaMin');

            if ($duracaoMin <= 0) {
                $duracaoMin = 30;
            }

            $dateEnd = (new \DateTime($dateStart))
                ->modify("+{$duracaoMin} minutes")
                ->format('Y-m-d H:i:s');
        }

        $where = [
            'profissionalId' => $profissionalId,
            'status!=' => ['Canceled', 'NoShow'],
            'dateStart<' => $dateEnd,
            'dateEnd>' => $dateStart,
            'deleted' => false,
        ];

        if (!$entity->isNew()) {
            $where['id!='] = $entity->getId();
        }

        $conflicting = $this->entityManager
            ->getRDBRepository('Appointment')
            ->where($where)
            ->findOne();

        if ($conflicting) {
            $conflictStart = $conflicting->get('dateStart');

            throw new BadRequest(
                "Conflito de agenda: o profissional já possui agendamento neste horário ({$conflictStart})."
            );
        }
    }
}

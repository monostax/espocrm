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
 * Validates that the Appointment's profissional is active and belongs
 * to the Appointment's unidade via team-based scoping (team intersection).
 */
class ValidateProfessionalUnit
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
        if ($entity->get('status') === 'Canceled') {
            return;
        }

        $profissionalId = $entity->get('profissionalId');
        $unidadeId = $entity->get('unidadeId');

        if (!$profissionalId || !$unidadeId) {
            return;
        }

        $isRelevantChange = $entity->isNew()
            || $entity->isAttributeChanged('profissionalId')
            || $entity->isAttributeChanged('unidadeId');

        if (!$isRelevantChange) {
            return;
        }

        $profissional = $this->entityManager->getEntityById('Profissional', $profissionalId);

        if (!$profissional) {
            throw new BadRequest('Profissional não encontrado.');
        }

        if (!$profissional->get('ativo')) {
            throw new BadRequest('Profissional não está ativo na unidade selecionada.');
        }

        $unidade = $this->entityManager->getEntityById('Unidade', $unidadeId);

        if (!$unidade) {
            throw new BadRequest('Unidade não encontrada.');
        }

        $profissionalTeamIds = $profissional->getLinkMultipleIdList('teams');
        $unidadeTeamIds = $unidade->getLinkMultipleIdList('teams');

        if (empty($profissionalTeamIds) || empty($unidadeTeamIds)) {
            throw new BadRequest('Profissional não está ativo na unidade selecionada.');
        }

        $sharedTeams = array_intersect($profissionalTeamIds, $unidadeTeamIds);

        if (empty($sharedTeams)) {
            throw new BadRequest('Profissional não está ativo na unidade selecionada.');
        }
    }
}

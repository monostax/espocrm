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

namespace Espo\Modules\FeatureClinica\Hooks\Atendimento;

use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

class CreateProntuarioEntry
{
    public static int $order = 10;

    public function __construct(
        private EntityManager $entityManager
    ) {}

    public function afterSave(Entity $entity, array $options): void
    {
        if (!$entity->isNew()) {
            return;
        }

        $pacienteId = $entity->get('pacienteId');
        $profissionalId = $entity->get('profissionalId');
        $unidadeId = $entity->get('unidadeId');

        if (!$pacienteId || !$profissionalId || !$unidadeId) {
            return;
        }

        $dataHora = $entity->get('dataHoraInicio') ?? date('Y-m-d H:i:s');
        $dateFormatted = date('d/m/Y', strtotime($dataHora));

        $prontuario = $this->entityManager->getNewEntity('Prontuario');
        $prontuario->set([
            'pacienteId' => $pacienteId,
            'profissionalId' => $profissionalId,
            'unidadeId' => $unidadeId,
            'dataHora' => $dataHora,
            'tipo' => 'Consulta',
            'titulo' => 'Atendimento - ' . $dateFormatted,
            'conteudo' => '',
            'atendimentoId' => $entity->getId(),
        ]);

        $jornadaId = $entity->get('jornadaId');
        if ($jornadaId) {
            $prontuario->set('jornadaId', $jornadaId);
        }

        $teamsIds = $entity->getLinkMultipleIdList('teams');
        if (!empty($teamsIds)) {
            $prontuario->set('teamsIds', $teamsIds);
        }

        $this->entityManager->saveEntity($prontuario);
    }
}

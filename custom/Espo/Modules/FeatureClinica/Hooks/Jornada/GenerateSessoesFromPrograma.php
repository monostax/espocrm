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

namespace Espo\Modules\FeatureClinica\Hooks\Jornada;

use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * When a Jornada is created with a programaId, auto-generates Sessao
 * records from the Programa's ProgramaItems. Also calculates
 * dataExpiracao from dataInicio + Programa.validadeDias.
 */
class GenerateSessoesFromPrograma
{
    public static int $order = 9;

    public function __construct(
        private EntityManager $entityManager
    ) {}

    public function afterSave(Entity $entity, array $options): void
    {
        if (!$entity->isNew()) {
            return;
        }

        $programaId = $entity->get('programaId');

        if (!$programaId) {
            return;
        }

        $programa = $this->entityManager->getEntityById('Programa', $programaId);

        if (!$programa) {
            return;
        }

        $teamsIds = $entity->getLinkMultipleIdList('teams');

        $this->createSessoes($entity, $programa, $teamsIds);
        $this->calculateDataExpiracao($entity, $programa);
    }

    private function createSessoes(Entity $jornada, Entity $programa, array $teamsIds): void
    {
        $itens = $this->entityManager
            ->getRDBRepository('ProgramaItem')
            ->where(['programaId' => $programa->getId()])
            ->order('ordem', 'ASC')
            ->find();

        $sequencia = 1;

        foreach ($itens as $item) {
            $quantidade = (int) $item->get('quantidade');
            if ($quantidade < 1) {
                $quantidade = 1;
            }

            for ($i = 0; $i < $quantidade; $i++) {
                $sessao = $this->entityManager->getNewEntity('Sessao');
                $sessao->set([
                    'jornadaId' => $jornada->getId(),
                    'procedimentoType' => $item->get('procedimentoType'),
                    'procedimentoId' => $item->get('procedimentoId'),
                    'sequencia' => $sequencia,
                    'status' => 'Pendente',
                    'unidadeId' => $jornada->get('unidadeId'),
                ]);

                if (!empty($teamsIds)) {
                    $sessao->set('teamsIds', $teamsIds);
                }

                $this->entityManager->saveEntity($sessao);
                $sequencia++;
            }
        }
    }

    private function calculateDataExpiracao(Entity $jornada, Entity $programa): void
    {
        $validadeDias = (int) $programa->get('validadeDias');
        $dataInicio = $jornada->get('dataInicio');

        if (!$validadeDias || !$dataInicio) {
            return;
        }

        $expiracao = (new \DateTime($dataInicio))
            ->modify("+{$validadeDias} days")
            ->format('Y-m-d');

        $jornada->set('dataExpiracao', $expiracao);
        $this->entityManager->saveEntity($jornada, ['skipHooks' => true]);
    }
}

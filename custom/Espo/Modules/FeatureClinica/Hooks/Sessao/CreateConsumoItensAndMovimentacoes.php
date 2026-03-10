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

/**
 * Creates ConsumoInsumo records from auto-populated templates and generates
 * MovimentacaoEstoque (Saida) for each ConsumoInsumo when a Sessao is
 * marked as "Realizada".
 */
class CreateConsumoItensAndMovimentacoes
{
    public static int $order = 12;

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

        $pending = $entity->get('_pendingConsumoItens');
        $teamsIds = $entity->getLinkMultipleIdList('teams');

        if (is_array($pending) && !empty($pending)) {
            foreach ($pending as $item) {
                $consumo = $this->entityManager->getNewEntity('ConsumoInsumo');
                $consumo->set([
                    'sessaoId' => $entity->getId(),
                    'insumoId' => $item['insumoId'],
                    'insumoLoteId' => $item['insumoLoteId'],
                    'quantidade' => $item['quantidade'],
                ]);

                if (!empty($teamsIds)) {
                    $consumo->set('teamsIds', $teamsIds);
                }

                $this->entityManager->saveEntity($consumo);
            }
        }

        $allItems = $this->entityManager
            ->getRDBRepository('ConsumoInsumo')
            ->where([
                'sessaoId' => $entity->getId(),
                'deleted' => false,
            ])
            ->find();

        $unidadeId = $entity->get('unidadeId');
        $profissionalId = $this->resolveProfissionalId($entity);

        foreach ($allItems as $item) {
            $insumoLoteId = $item->get('insumoLoteId');

            if (!$insumoLoteId) {
                continue;
            }

            $movimentacao = $this->entityManager->getNewEntity('MovimentacaoEstoque');
            $movimentacao->set([
                'insumoLoteId' => $insumoLoteId,
                'unidadeId' => $unidadeId,
                'tipo' => 'Saida',
                'quantidade' => (float) $item->get('quantidade'),
                'origemType' => 'Sessao',
                'origemId' => $entity->getId(),
                'profissionalId' => $profissionalId,
                'dataHora' => date('Y-m-d H:i:s'),
            ]);

            if (!empty($teamsIds)) {
                $movimentacao->set('teamsIds', $teamsIds);
            }

            $this->entityManager->saveEntity($movimentacao);
        }
    }

    private function resolveProfissionalId(Entity $sessao): ?string
    {
        $appointmentId = $sessao->get('appointmentId');

        if ($appointmentId) {
            $appointment = $this->entityManager->getEntityById('Appointment', $appointmentId);

            if ($appointment) {
                $atendimentoId = $appointment->get('atendimentoId');

                if ($atendimentoId) {
                    $atendimento = $this->entityManager->getEntityById('Atendimento', $atendimentoId);

                    if ($atendimento && $atendimento->get('profissionalId')) {
                        return $atendimento->get('profissionalId');
                    }
                }
            }
        }

        $jornadaId = $sessao->get('jornadaId');

        if ($jornadaId) {
            $jornada = $this->entityManager->getEntityById('Jornada', $jornadaId);

            if ($jornada && $jornada->get('profissionalId')) {
                return $jornada->get('profissionalId');
            }
        }

        return null;
    }
}

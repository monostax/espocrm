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

namespace Espo\Modules\FeatureClinica\Hooks\ProcedimentoRealizado;

use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Auto-creates a MovimentacaoEstoque (Saida) when a ProcedimentoRealizado
 * is created for a stock-controlled procedure with an insumoLoteId set.
 */
class CreateMovimentacaoEstoque
{
    public static int $order = 11;

    public function __construct(
        private EntityManager $entityManager
    ) {}

    public function afterSave(Entity $entity, array $options): void
    {
        if (!$entity->isNew()) {
            return;
        }

        $insumoLoteId = $entity->get('insumoLoteId');

        if (!$insumoLoteId) {
            return;
        }

        $procedimentoType = $entity->get('procedimentoType');

        if ($procedimentoType === 'ProcedimentoInjetavel') {
            $procedimentoId = $entity->get('procedimentoId');

            if (!$procedimentoId) {
                return;
            }

            $procedimento = $this->entityManager->getEntityById('ProcedimentoInjetavel', $procedimentoId);

            if (!$procedimento || !$procedimento->get('controlaEstoque')) {
                return;
            }
        } elseif ($procedimentoType !== 'ProcedimentoImplante') {
            return;
        }

        $atendimentoId = $entity->get('atendimentoId');
        $unidadeId = null;
        $profissionalId = null;

        if ($atendimentoId) {
            $atendimento = $this->entityManager->getEntityById('Atendimento', $atendimentoId);
            if ($atendimento) {
                $unidadeId = $atendimento->get('unidadeId');
                $profissionalId = $atendimento->get('profissionalId');
            }
        }

        if (!$unidadeId) {
            $sessaoId = $entity->get('sessaoId');
            if ($sessaoId) {
                $sessao = $this->entityManager->getEntityById('Sessao', $sessaoId);
                if ($sessao) {
                    $unidadeId = $sessao->get('unidadeId');
                    $jornadaId = $sessao->get('jornadaId');
                    if (!$profissionalId && $jornadaId) {
                        $jornada = $this->entityManager->getEntityById('Jornada', $jornadaId);
                        if ($jornada) {
                            $profissionalId = $jornada->get('profissionalId');
                        }
                    }
                }
            }
        }

        if (!$unidadeId) {
            return;
        }

        $movQuantidade = (float) ($entity->get('quantidade') ?? 1);

        $insumoLote = $this->entityManager->getEntityById('InsumoLote', $insumoLoteId);
        if ($insumoLote) {
            $insumoId = $insumoLote->get('insumoId');
            if ($insumoId) {
                $insumo = $this->entityManager->getEntityById('Insumo', $insumoId);
                // Use unidadeDosagem for clinical dosing checks (deprecated unidadeMedida)
                if ($insumo && $insumo->get('unidadeDosagem')) {
                    $dosagemAplicada = (float) $entity->get('dosagemAplicada');
                    if ($dosagemAplicada > 0) {
                        $movQuantidade = $dosagemAplicada;
                    }
                }
            }
        }

        $teamsIds = $entity->getLinkMultipleIdList('teams');

        $movimentacao = $this->entityManager->getNewEntity('MovimentacaoEstoque');
        $movimentacao->set([
            'insumoLoteId' => $insumoLoteId,
            'unidadeId' => $unidadeId,
            'tipo' => 'Saida',
            'quantidade' => $movQuantidade,
            'origemType' => 'ProcedimentoRealizado',
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

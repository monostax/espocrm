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

namespace Espo\Modules\FeatureClinica\Hooks\LancamentoFinanceiro;

use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Calculates the convênio/patient split on LancamentoFinanceiro
 * based on ConvenioRegra coverage rules.
 */
class CalculateConvenioSplit
{
    public static int $order = 10;

    public function __construct(
        private EntityManager $entityManager
    ) {}

    public function beforeSave(Entity $entity, array $options): void
    {
        $shouldCalculate = $entity->isNew()
            || $entity->isAttributeChanged('convenioId')
            || $entity->isAttributeChanged('valorLiquido');

        if (!$shouldCalculate) {
            return;
        }

        $convenioId = $entity->get('convenioId');
        $valorLiquido = (float) $entity->get('valorLiquido');

        if (empty($convenioId)) {
            $entity->set('percentualConvenio', 0);
            $entity->set('valorConvenio', 0);
            $entity->set('valorPaciente', $valorLiquido);
            return;
        }

        $convenioRegra = $this->findConvenioRegra($entity, $convenioId);

        if (!$convenioRegra || $convenioRegra->get('cobertura') === 'NaoCoberto') {
            $entity->set('percentualConvenio', 0);
            $entity->set('valorConvenio', 0);
            $entity->set('valorPaciente', $valorLiquido);
            return;
        }

        $percentual = 0;

        if ($convenioRegra->get('cobertura') === 'Total') {
            $percentual = 100;
        } elseif ($convenioRegra->get('cobertura') === 'Parcial') {
            $percentual = (float) $convenioRegra->get('percentualCobertura');
        }

        $valorConvenio = $valorLiquido * ($percentual / 100);

        $valorFixo = $convenioRegra->get('valorFixo');
        if (!empty($valorFixo) && (float) $valorFixo > 0) {
            $valorConvenio = min($valorConvenio, (float) $valorFixo);
        }

        $valorPaciente = $valorLiquido - $valorConvenio;

        $entity->set('percentualConvenio', $percentual);
        $entity->set('valorConvenio', $valorConvenio);
        $entity->set('valorPaciente', $valorPaciente);
    }

    private function findConvenioRegra(Entity $entity, string $convenioId): ?Entity
    {
        $origemType = $entity->get('origemType');
        $origemId = $entity->get('origemId');

        if ($origemType === 'Orcamento' && $origemId) {
            $firstItem = $this->entityManager
                ->getRDBRepository('OrcamentoItem')
                ->where(['orcamentoId' => $origemId])
                ->order('createdAt', 'ASC')
                ->findOne();

            if ($firstItem && $firstItem->get('procedimentoType') && $firstItem->get('procedimentoId')) {
                $regra = $this->queryConvenioRegra(
                    $convenioId,
                    $firstItem->get('procedimentoType'),
                    $firstItem->get('procedimentoId')
                );

                if ($regra) {
                    return $regra;
                }
            }
        }

        return $this->entityManager
            ->getRDBRepository('ConvenioRegra')
            ->where([
                'convenioId' => $convenioId,
            ])
            ->order('vigenciaInicio', 'DESC')
            ->findOne();
    }

    private function queryConvenioRegra(
        string $convenioId,
        string $procedimentoType,
        string $procedimentoId
    ): ?Entity {
        return $this->entityManager
            ->getRDBRepository('ConvenioRegra')
            ->where([
                'convenioId' => $convenioId,
                'procedimentoType' => $procedimentoType,
                'procedimentoId' => $procedimentoId,
            ])
            ->order('vigenciaInicio', 'DESC')
            ->findOne();
    }
}

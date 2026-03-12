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

class SnapshotPrice
{
    public static int $order = 10;

    public function __construct(
        private EntityManager $entityManager
    ) {}

    public function beforeSave(Entity $entity, array $options): void
    {
        $procedimentoType = $entity->get('procedimentoType');
        $procedimentoId = $entity->get('procedimentoId');

        if (!$procedimentoType || !$procedimentoId) {
            return;
        }

        if (!$entity->isNew() && !$entity->isAttributeChanged('procedimentoId') && !$entity->isAttributeChanged('procedimentoType')) {
            return;
        }

        if ($entity->get('valorCobrado') !== null) {
            return;
        }

        $unidadeId = null;
        $atendimentoId = $entity->get('atendimentoId');

        if ($atendimentoId) {
            $atendimento = $this->entityManager->getEntityById('Atendimento', $atendimentoId);
            if ($atendimento) {
                $unidadeId = $atendimento->get('unidadeId');
            }
        }

        $tabela = $this->findActivePrice($procedimentoType, $procedimentoId, $unidadeId);

        if (!$tabela && $unidadeId) {
            $tabela = $this->findActivePrice($procedimentoType, $procedimentoId, null);
        }

        if ($tabela) {
            $entity->set('valorCobrado', $tabela->get('valor'));
            $entity->set('valorCobradoCurrency', $tabela->get('valorCurrency'));
        }
    }

    private function findActivePrice(string $procedimentoType, string $procedimentoId, ?string $unidadeId): ?Entity
    {
        $today = date('Y-m-d');

        $where = [
            'procedimentoType' => $procedimentoType,
            'procedimentoId' => $procedimentoId,
            'ativo' => true,
            [
                'OR' => [
                    ['vigenciaFim' => null],
                    ['vigenciaFim>=' => $today],
                ],
            ],
            'vigenciaInicio<=' => $today,
        ];

        if ($unidadeId) {
            $where['unidadeId'] = $unidadeId;
        } else {
            $where['unidadeId'] = null;
        }

        return $this->entityManager
            ->getRDBRepository('TabelaDePrecos')
            ->where($where)
            ->order('vigenciaInicio', 'DESC')
            ->findOne();
    }
}

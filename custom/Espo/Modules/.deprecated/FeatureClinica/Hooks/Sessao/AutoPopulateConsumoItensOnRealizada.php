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

use Espo\Core\Exceptions\BadRequest;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Reads the procedure's ConsumoInsumoPadrao templates and prepares
 * ConsumoInsumo data with FIFO lot auto-selection when a Sessao
 * is marked as "Realizada". Stores pending items in _pendingConsumoItens
 * for downstream hooks.
 */
class AutoPopulateConsumoItensOnRealizada
{
    public static int $order = 4;

    public function __construct(
        private EntityManager $entityManager
    ) {}

    /**
     * @throws BadRequest
     */
    public function beforeSave(Entity $entity, array $options): void
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

        $templates = $this->entityManager
            ->getRDBRepository('ConsumoInsumoPadrao')
            ->where([
                'procedimentoType' => $procedimentoType,
                'procedimentoId' => $procedimentoId,
                'deleted' => false,
            ])
            ->find();

        $templateList = [];
        foreach ($templates as $template) {
            $templateList[] = $template;
        }

        if (empty($templateList)) {
            return;
        }

        $existingItems = $this->entityManager
            ->getRDBRepository('ConsumoInsumo')
            ->where([
                'sessaoId' => $entity->getId(),
                'deleted' => false,
            ])
            ->find();

        $coveredInsumoIds = [];
        foreach ($existingItems as $item) {
            $insumoId = $item->get('insumoId');
            if ($insumoId) {
                $coveredInsumoIds[$insumoId] = true;
            }
        }

        $unidadeId = $entity->get('unidadeId');
        $pending = [];

        foreach ($templateList as $template) {
            $insumoId = $template->get('insumoId');

            if (!$insumoId || isset($coveredInsumoIds[$insumoId])) {
                continue;
            }

            $quantidade = (float) ($template->get('quantidade') ?? 1);

            $lot = $this->findBestAvailableLot($insumoId, $unidadeId, $quantidade);

            if (!$lot) {
                $insumo = $this->entityManager->getEntityById('Insumo', $insumoId);
                $insumoNome = $insumo ? $insumo->get('nome') : $insumoId;

                throw new BadRequest(
                    "Estoque insuficiente para o insumo: {$insumoNome}. " .
                    "Nenhum lote disponível na unidade com quantidade suficiente."
                );
            }

            $pending[] = [
                'insumoId' => $insumoId,
                'insumoLoteId' => $lot->getId(),
                'quantidade' => $quantidade,
            ];
        }

        $entity->set('_pendingConsumoItens', $pending);
    }

    private function findBestAvailableLot(
        string $insumoId,
        ?string $unidadeId,
        float $quantidadeNeeded
    ): ?Entity {
        $where = [
            'insumoId' => $insumoId,
            'status' => 'Disponivel',
            'quantidadeAtual>=' => $quantidadeNeeded,
            'deleted' => false,
        ];

        if ($unidadeId) {
            $where['unidadeId'] = $unidadeId;
        }

        return $this->entityManager
            ->getRDBRepository('InsumoLote')
            ->where($where)
            ->order('dataValidade', 'ASC')
            ->findOne();
    }
}

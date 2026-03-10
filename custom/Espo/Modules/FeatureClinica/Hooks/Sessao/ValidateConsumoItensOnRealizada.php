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
 * Validates stock sufficiency for all ConsumoInsumo items (manually added
 * and pending auto-populated) when a Sessao is marked as "Realizada".
 */
class ValidateConsumoItensOnRealizada
{
    public static int $order = 6;

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

        $allItems = [];

        $existingItems = $this->entityManager
            ->getRDBRepository('ConsumoInsumo')
            ->where([
                'sessaoId' => $entity->getId(),
                'deleted' => false,
            ])
            ->find();

        foreach ($existingItems as $item) {
            $allItems[] = [
                'insumoLoteId' => $item->get('insumoLoteId'),
                'quantidade' => (float) $item->get('quantidade'),
            ];
        }

        $pending = $entity->get('_pendingConsumoItens');
        if (is_array($pending)) {
            foreach ($pending as $item) {
                $allItems[] = [
                    'insumoLoteId' => $item['insumoLoteId'],
                    'quantidade' => $item['quantidade'],
                ];
            }
        }

        if (empty($allItems)) {
            return;
        }

        $lotDemand = [];
        foreach ($allItems as $item) {
            $loteId = $item['insumoLoteId'];
            if (!$loteId) {
                continue;
            }
            if (!isset($lotDemand[$loteId])) {
                $lotDemand[$loteId] = 0.0;
            }
            $lotDemand[$loteId] += $item['quantidade'];
        }

        foreach ($lotDemand as $loteId => $totalNeeded) {
            $lote = $this->entityManager->getEntityById('InsumoLote', $loteId);

            if (!$lote) {
                throw new BadRequest(
                    "Lote de insumo não encontrado (ID: {$loteId})."
                );
            }

            $quantidadeAtual = (float) $lote->get('quantidadeAtual');

            if ($totalNeeded > $quantidadeAtual) {
                $insumoId = $lote->get('insumoId');
                $insumo = $insumoId
                    ? $this->entityManager->getEntityById('Insumo', $insumoId)
                    : null;

                $insumoNome = $insumo ? $insumo->get('nome') : '';
                $loteNumero = $lote->get('numeroLote');
                $unidade = $insumo ? ($insumo->get('unidadeMedida') ?? '') : '';

                $dispStr = number_format($quantidadeAtual, 2, ',', '.');
                $necStr = number_format($totalNeeded, 2, ',', '.');

                throw new BadRequest(
                    "Estoque insuficiente para {$insumoNome} (lote {$loteNumero}). " .
                    "Disponível: {$dispStr} {$unidade}, necessário: {$necStr} {$unidade}."
                );
            }
        }
    }
}

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
 * Centralized hook for updating InsumoLote saldo when ConsumoInsumo is created
 * during session realization.
 * 
 * Runs AFTER CreateConsumoItensAndMovimentacoes (order = 12) with order = 20.
 * Uses atomic database updates for concurrent session safety.
 */
class ProcessInsumoConsumo
{
    public static int $order = 20;

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

        // Get all ConsumoInsumo records for this session
        $consumoItens = $this->entityManager
            ->getRDBRepository('ConsumoInsumo')
            ->where([
                'sessaoId' => $entity->getId(),
                'deleted' => false,
            ])
            ->find();

        foreach ($consumoItens as $consumo) {
            $this->processConsumo($consumo, $entity);
        }
    }

    private function processConsumo(Entity $consumo, Entity $sessao): void
    {
        $insumoLoteId = $consumo->get('insumoLoteId');
        
        if (empty($insumoLoteId)) {
            return;
        }

        $insumoLote = $this->entityManager->getEntity('InsumoLote', $insumoLoteId);
        
        if (!$insumoLote) {
            return;
        }

        $insumoTipo = $insumoLote->get('insumoTipo');
        
        // Only process Medicamento and Cosmetico types
        if (!in_array($insumoTipo, ['Medicamento', 'Cosmetico'])) {
            return;
        }

        $quantidade = (float) $consumo->get('quantidade');
        
        if ($quantidade <= 0) {
            return;
        }

        $concentracao = (float) $insumoLote->get('insumoConcentracao');
        $dosagemMg = null;
        $volumeMl = null;

        // Calculate dosage and volume based on concentration
        if ($insumoTipo === 'Medicamento' && $concentracao > 0) {
            // concentracao = dosagemTotal / volumeTotal (mg/mL)
            // For each unit consumed:
            // - dosagemMg = quantidade * dosagemTotal (total mg per unit)
            // - volumeMl = quantidade * volumeTotal (total mL per unit)
            $dosagemTotal = (float) $insumoLote->get('insumoDosagemTotal');
            $volumeTotal = (float) $insumoLote->get('insumoVolumeTotal');
            
            if ($dosagemTotal > 0) {
                $dosagemMg = $quantidade * $dosagemTotal;
            }
            if ($volumeTotal > 0) {
                $volumeMl = $quantidade * $volumeTotal;
            }
        } elseif ($insumoTipo === 'Cosmetico') {
            $volumeTotal = (float) $insumoLote->get('insumoVolumeTotal');
            if ($volumeTotal > 0) {
                $volumeMl = $quantidade * $volumeTotal;
            }
        }

        // Use atomic SQL updates for concurrent session safety
        $pdo = $this->entityManager->getPDO();
        
        if ($dosagemMg !== null && $dosagemMg > 0) {
            $sql = "UPDATE insumo_lote SET saldo_mg = GREATEST(0, saldo_mg - :delta_mg) WHERE id = :id";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':delta_mg' => $dosagemMg,
                ':id' => $insumoLoteId
            ]);
        }

        if ($volumeMl !== null && $volumeMl > 0) {
            $sql = "UPDATE insumo_lote SET saldo_ml = GREATEST(0, saldo_ml - :delta_ml) WHERE id = :id";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                ':delta_ml' => $volumeMl,
                ':id' => $insumoLoteId
            ]);
        }

        // Increment sessoesUsadas
        $sql = "UPDATE insumo_lote SET sessoes_usadas = sessoes_usadas + 1 WHERE id = :id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':id' => $insumoLoteId]);

        // Get updated saldo values
        $insumoLote = $this->entityManager->getEntity('InsumoLote', $insumoLoteId);
        $saldoRestanteMg = $insumoLote->get('saldoMg');
        $saldoRestanteMl = $insumoLote->get('saldoMl');

        // Update ConsumoInsumo with dosage/volume and remaining saldo
        $consumo->set([
            'concentracao' => $concentracao > 0 ? $concentracao : null,
            'dosagemMg' => $dosagemMg,
            'volumeMl' => $volumeMl,
            'saldoRestanteMg' => $saldoRestanteMg,
            'saldoRestanteMl' => $saldoRestanteMl,
        ]);
        
        $this->entityManager->saveEntity($consumo);

        // Update Sessao with volumeAplicado if not already set
        if ($volumeMl !== null && $volumeMl > 0 && empty($sessao->get('volumeAplicado'))) {
            $sessao->set('volumeAplicado', $volumeMl);
            $this->entityManager->saveEntity($sessao);
        }
    }
}
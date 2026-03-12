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

namespace Espo\Modules\FeatureClinica\Migration;

use Espo\Core\Utils\Log;
use Espo\ORM\EntityManager;

/**
 * Migration script to backfill new stock tracking fields.
 * 
 * Run this migration after deploying entityDefs changes but before
 * the new hooks are active.
 * 
 * Usage:
 *   php command.php migration:run FeatureClinica:PopulateStockFields
 */
class PopulateStockFields
{
    private Log $log;
    private EntityManager $entityManager;

    public function __construct(
        Log $log,
        EntityManager $entityManager
    ) {
        $this->log = $log;
        $this->entityManager = $entityManager;
    }

    /**
     * Run the migration.
     */
    public function run(): array
    {
        $results = [
            'insumo' => $this->migrateInsumo(),
            'insumoLote' => $this->migrateInsumoLote(),
            'consumoInsumo' => $this->migrateConsumoInsumo(),
        ];

        $this->log->info('PopulateStockFields migration completed', $results);

        return $results;
    }

    /**
     * Migrate Insumo records:
     * - Calculate concentracao = dosagemTotal / volumeTotal for Medicamentos
     */
    private function migrateInsumo(): array
    {
        $updated = 0;
        $skipped = 0;

        $insumos = $this->entityManager
            ->getRDBRepository('Insumo')
            ->where([
                'tipo' => ['Medicamento', 'Cosmetico'],
            ])
            ->find();

        foreach ($insumos as $insumo) {
            $dosagemTotal = $insumo->get('dosagemTotal');
            $volumeTotal = $insumo->get('volumeTotal');
            $concentracao = $insumo->get('concentracao');

            // Skip if already has concentracao or missing required fields
            if ($concentracao !== null && $concentracao > 0) {
                $skipped++;
                continue;
            }

            if (!$dosagemTotal || !$volumeTotal || $volumeTotal <= 0) {
                $skipped++;
                continue;
            }

            $calculatedConcentracao = $dosagemTotal / $volumeTotal;

            $insumo->set('concentracao', round($calculatedConcentracao, 4));
            $this->entityManager->saveEntity($insumo, ['skipHooks' => true]);
            $updated++;
        }

        return [
            'updated' => $updated,
            'skipped' => $skipped,
        ];
    }

    /**
     * Migrate InsumoLote records:
     * - Set saldoMg/saldoMl based on quantidadeAtual and insumo values
     * - Set statusUso based on consumption state
     */
    private function migrateInsumoLote(): array
    {
        $updated = 0;
        $skipped = 0;

        $lotes = $this->entityManager
            ->getRDBRepository('InsumoLote')
            ->find();

        foreach ($lotes as $lote) {
            $insumoId = $lote->get('insumoId');
            if (!$insumoId) {
                $skipped++;
                continue;
            }

            $insumo = $this->entityManager->getEntityById('Insumo', $insumoId);
            if (!$insumo) {
                $skipped++;
                continue;
            }

            $tipo = $insumo->get('tipo');
            $quantidadeAtual = (float) ($lote->get('quantidadeAtual') ?? 0);

            $updateData = [];

            // Calculate saldoMg and saldoMl for Medicamento/Cosmetico
            if (in_array($tipo, ['Medicamento', 'Cosmetico'])) {
                $dosagemTotal = (float) ($insumo->get('dosagemTotal') ?? 0);
                $volumeTotal = (float) ($insumo->get('volumeTotal') ?? 0);

                if ($tipo === 'Medicamento' && $dosagemTotal > 0) {
                    $updateData['saldoMg'] = $quantidadeAtual * $dosagemTotal;
                }

                if ($volumeTotal > 0) {
                    $updateData['saldoMl'] = $quantidadeAtual * $volumeTotal;
                }
            }

            // Determine statusUso
            $statusUso = $this->determineStatusUso($lote, $quantidadeAtual);
            if ($statusUso) {
                $updateData['statusUso'] = $statusUso;
            }

            if (!empty($updateData)) {
                $lote->set($updateData);
                $this->entityManager->saveEntity($lote, ['skipHooks' => true]);
                $updated++;
            } else {
                $skipped++;
            }
        }

        return [
            'updated' => $updated,
            'skipped' => $skipped,
        ];
    }

    /**
     * Determine statusUso for an InsumoLote.
     */
    private function determineStatusUso($lote, float $quantidadeAtual): ?string
    {
        // Check if already has statusUso
        $currentStatusUso = $lote->get('statusUso');
        
        // Check for consumptions
        $consumoCount = $this->entityManager
            ->getRDBRepository('ConsumoInsumo')
            ->where([
                'insumoLoteId' => $lote->getId(),
                'deleted' => false,
            ])
            ->count();

        if ($quantidadeAtual <= 0) {
            return 'Esgotada';
        }

        if ($consumoCount > 0) {
            return 'EmUso';
        }

        return 'Nova';
    }

    /**
     * Migrate ConsumoInsumo records:
     * - Mark legacy records (cannot backfill dosagemMg/volumeMl without historical data)
     * - Set saldoRestante fields to NULL (unknown)
     */
    private function migrateConsumoInsumo(): array
    {
        $marked = 0;
        $skipped = 0;

        $consumos = $this->entityManager
            ->getRDBRepository('ConsumoInsumo')
            ->where([
                'dosagemMg' => null,
                'volumeMl' => null,
            ])
            ->find();

        foreach ($consumos as $consumo) {
            // Set saldoRestante fields to NULL (unknown for legacy records)
            // These cannot be calculated without historical concentration data
            $consumo->set([
                'saldoRestanteMg' => null,
                'saldoRestanteMl' => null,
            ]);

            $this->entityManager->saveEntity($consumo, ['skipHooks' => true]);
            $marked++;
        }

        return [
            'markedLegacy' => $marked,
            'skipped' => $skipped,
        ];
    }
}

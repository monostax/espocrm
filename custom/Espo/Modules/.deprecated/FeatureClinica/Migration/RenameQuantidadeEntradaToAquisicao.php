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
 * Migration script to rename quantidadeEntrada to quantidadeAquisicao
 * and recalculate quantidadeAtual based on quantidadePorUnidade.
 * 
 * This migration:
 * 1. Renames the column quantidadeEntrada to quantidadeAquisicao
 * 2. Recalculates quantidadeAtual = quantidadeAquisicao × quantidadePorUnidade
 * 3. Recalculates saldoMg and saldoMl based on new quantidadeAtual
 * 
 * Usage:
 *   php command.php migration:run FeatureClinica:RenameQuantidadeEntradaToAquisicao
 */
class RenameQuantidadeEntradaToAquisicao
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
            'columnRenamed' => $this->renameColumn(),
            'lotesUpdated' => $this->recalculateLotes(),
        ];

        $this->log->info('RenameQuantidadeEntradaToAquisicao migration completed', $results);

        return $results;
    }

    /**
     * Rename the database column from quantidadeEntrada to quantidadeAquisicao.
     */
    private function renameColumn(): array
    {
        $pdo = $this->entityManager->getPDO();
        $tableName = 'insumo_lote';
        
        try {
            // Check if old column exists
            $checkOld = $pdo->query("SHOW COLUMNS FROM `{$tableName}` LIKE 'quantidade_entrada'");
            if ($checkOld->rowCount() === 0) {
                return ['status' => 'skipped', 'message' => 'Column quantidade_entrada does not exist'];
            }
            
            // Check if new column already exists
            $checkNew = $pdo->query("SHOW COLUMNS FROM `{$tableName}` LIKE 'quantidade_aquisicao'");
            if ($checkNew->rowCount() > 0) {
                return ['status' => 'skipped', 'message' => 'Column quantidade_aquisicao already exists'];
            }
            
            // Rename the column
            $pdo->exec("ALTER TABLE `{$tableName}` CHANGE COLUMN `quantidade_entrada` `quantidade_aquisicao` DOUBLE");
            
            return ['status' => 'success', 'message' => 'Column renamed successfully'];
        } catch (\Exception $e) {
            $this->log->error('Failed to rename column', ['error' => $e->getMessage()]);
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    /**
     * Recalculate quantidadeAtual, saldoMg, and saldoMl for all InsumoLote records.
     * 
     * quantidadeAtual = quantidadeAquisicao × insumo.quantidadePorUnidade
     * saldoMg = quantidadeAtual × insumo.dosagemTotal
     * saldoMl = quantidadeAtual × insumo.volumeTotal
     */
    private function recalculateLotes(): array
    {
        $updated = 0;
        $skipped = 0;
        $errors = 0;

        // Get all InsumoLote records with their related Insumo data
        $lotes = $this->entityManager
            ->getRDBRepository('InsumoLote')
            ->join('insumo')
            ->find();

        foreach ($lotes as $lote) {
            try {
                $insumo = $lote->get('insumo');
                if (!$insumo) {
                    $skipped++;
                    continue;
                }

                $quantidadeAquisicao = (float) ($lote->get('quantidadeAquisicao') ?? 0);
                $quantidadePorUnidade = (float) ($insumo->get('quantidadePorUnidade') ?? 1);
                
                // Calculate quantidadeAtual
                // If quantidadePorUnidade is 1 or not set, assume the old value was already in individual units
                // This handles the case where the data was already correct
                if ($quantidadePorUnidade <= 1) {
                    // Keep existing quantidadeAtual as-is
                    $skipped++;
                    continue;
                }
                
                $quantidadeAtual = $quantidadeAquisicao * $quantidadePorUnidade;
                
                $updateData = [
                    'quantidadeAtual' => $quantidadeAtual,
                ];
                
                // Recalculate saldoMg and saldoMl for Medicamentos
                $tipo = $insumo->get('tipo');
                if ($tipo === 'Medicamento') {
                    $dosagemTotal = (float) ($insumo->get('dosagemTotal') ?? 0);
                    $volumeTotal = (float) ($insumo->get('volumeTotal') ?? 0);
                    
                    if ($dosagemTotal > 0) {
                        $updateData['saldoMg'] = $quantidadeAtual * $dosagemTotal;
                    }
                    
                    if ($volumeTotal > 0) {
                        $updateData['saldoMl'] = $quantidadeAtual * $volumeTotal;
                    }
                } elseif ($tipo === 'Cosmetico') {
                    $volumeTotal = (float) ($insumo->get('volumeTotal') ?? 0);
                    
                    if ($volumeTotal > 0) {
                        $updateData['saldoMl'] = $quantidadeAtual * $volumeTotal;
                    }
                }
                
                $lote->set($updateData);
                $this->entityManager->saveEntity($lote, ['skipHooks' => true]);
                $updated++;
                
            } catch (\Exception $e) {
                $this->log->error('Failed to update InsumoLote', [
                    'id' => $lote->getId(),
                    'error' => $e->getMessage(),
                ]);
                $errors++;
            }
        }

        return [
            'updated' => $updated,
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }
}

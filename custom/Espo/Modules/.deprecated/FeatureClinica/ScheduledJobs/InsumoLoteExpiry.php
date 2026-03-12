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

namespace Espo\Modules\FeatureClinica\ScheduledJobs;

use Espo\Core\Job\JobDataLess;
use Espo\Core\Utils\Log;
use Espo\ORM\EntityManager;

/**
 * Daily job that handles InsumoLote expiry, near-expiry alerts,
 * and minimum stock level alerts via Task creation.
 */
class InsumoLoteExpiry implements JobDataLess
{
    public function __construct(
        private EntityManager $entityManager,
        private Log $log,
    ) {}

    public function run(): void
    {
        $this->expireOverdueLotes();
        $this->alertNearExpiryLotes();
        $this->alertLowStock();
    }

    private function expireOverdueLotes(): void
    {
        $today = date('Y-m-d');

        $lotes = $this->entityManager
            ->getRDBRepository('InsumoLote')
            ->where([
                'status' => 'Disponivel',
                'dataValidade<' => $today,
            ])
            ->find();

        $count = 0;

        foreach ($lotes as $lote) {
            $lote->set('status', 'Vencido');
            $this->entityManager->saveEntity($lote);

            $insumoNome = $this->getInsumoNome($lote->get('insumoId'));
            $numeroLote = $lote->get('numeroLote');

            $task = $this->entityManager->getNewEntity('Task');
            $task->set([
                'name' => "Lote vencido: {$insumoNome} - Lote {$numeroLote}",
                'status' => 'Not Started',
                'assignedUserId' => '1',
                'dateEnd' => date('Y-m-d', strtotime('+3 days')),
            ]);
            $this->entityManager->saveEntity($task);

            $count++;
        }

        $this->log->info("InsumoLoteExpiry: {$count} lote(s) expired.");
    }

    private function alertNearExpiryLotes(): void
    {
        $today = date('Y-m-d');
        $thirtyDaysFromNow = date('Y-m-d', strtotime('+30 days'));
        $currentMonth = date('Y-m');

        $lotes = $this->entityManager
            ->getRDBRepository('InsumoLote')
            ->where([
                'status' => 'Disponivel',
                'dataValidade>=' => $today,
                'dataValidade<=' => $thirtyDaysFromNow,
            ])
            ->find();

        $count = 0;

        foreach ($lotes as $lote) {
            $insumoNome = $this->getInsumoNome($lote->get('insumoId'));
            $numeroLote = $lote->get('numeroLote');
            $dataValidade = $lote->get('dataValidade');

            $taskName = "Lote próximo do vencimento: {$insumoNome} - Lote {$numeroLote} (vence em {$dataValidade})";

            $existing = $this->entityManager
                ->getRDBRepository('Task')
                ->where([
                    'name' => $taskName,
                    'createdAt>=' => $currentMonth . '-01 00:00:00',
                ])
                ->findOne();

            if ($existing) {
                continue;
            }

            $task = $this->entityManager->getNewEntity('Task');
            $task->set([
                'name' => $taskName,
                'status' => 'Not Started',
                'assignedUserId' => '1',
                'dateEnd' => $dataValidade,
            ]);
            $this->entityManager->saveEntity($task);

            $count++;
        }

        $this->log->info("InsumoLoteExpiry: {$count} near-expiry alert(s) created.");
    }

    private function alertLowStock(): void
    {
        $currentMonth = date('Y-m');

        $sql = "
            SELECT il.insumo_id AS insumoId,
                   il.unidade_id AS unidadeId,
                   SUM(il.quantidade_atual) AS totalQuantidade
            FROM insumo_lote AS il
            WHERE il.status = 'Disponivel'
              AND il.deleted = 0
            GROUP BY il.insumo_id, il.unidade_id
        ";

        $pdo = $this->entityManager->getPDO();
        $sth = $pdo->query($sql);
        $rows = $sth->fetchAll(\PDO::FETCH_ASSOC);

        $count = 0;

        foreach ($rows as $row) {
            $insumoId = $row['insumoId'];
            $unidadeId = $row['unidadeId'];
            $totalQuantidade = (float) $row['totalQuantidade'];

            $insumo = $this->entityManager->getEntityById('Insumo', $insumoId);

            if (!$insumo) {
                continue;
            }

            $estoqueMinimo = (float) $insumo->get('estoqueMinimo');

            if ($estoqueMinimo <= 0 || $totalQuantidade >= $estoqueMinimo) {
                continue;
            }

            $insumoNome = $insumo->get('nome');
            $unidadeNome = $this->getUnidadeNome($unidadeId);

            $existing = $this->entityManager
                ->getRDBRepository('Task')
                ->where([
                    'name' => "Estoque baixo: {$insumoNome} na unidade {$unidadeNome}",
                    'createdAt>=' => $currentMonth . '-01 00:00:00',
                ])
                ->findOne();

            if ($existing) {
                continue;
            }

            $task = $this->entityManager->getNewEntity('Task');
            $task->set([
                'name' => "Estoque baixo: {$insumoNome} na unidade {$unidadeNome}",
                'status' => 'Not Started',
                'assignedUserId' => '1',
                'dateEnd' => date('Y-m-d', strtotime('+7 days')),
            ]);
            $this->entityManager->saveEntity($task);

            $count++;
        }

        $this->log->info("InsumoLoteExpiry: {$count} low-stock alert(s) created.");
    }

    private function getInsumoNome(?string $insumoId): string
    {
        if (!$insumoId) {
            return '(desconhecido)';
        }

        $insumo = $this->entityManager->getEntityById('Insumo', $insumoId);

        return $insumo ? ($insumo->get('nome') ?? '(desconhecido)') : '(desconhecido)';
    }

    private function getUnidadeNome(?string $unidadeId): string
    {
        if (!$unidadeId) {
            return '(desconhecida)';
        }

        $unidade = $this->entityManager->getEntityById('Unidade', $unidadeId);

        return $unidade ? ($unidade->get('nome') ?? '(desconhecida)') : '(desconhecida)';
    }
}

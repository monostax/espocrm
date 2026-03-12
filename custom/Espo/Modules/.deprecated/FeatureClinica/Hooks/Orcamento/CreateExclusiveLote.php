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

namespace Espo\Modules\FeatureClinica\Hooks\Orcamento;

use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Creates exclusive InsumoLote when Orcamento is approved with
 * exclusive ampola items. Runs after ApproveGenerateJornada.
 */
class CreateExclusiveLote
{
    public static int $order = 10;

    public function __construct(
        private EntityManager $entityManager
    ) {}

    public function afterSave(Entity $entity, array $options): void
    {
        if (!$entity->isAttributeChanged('status')) {
            return;
        }

        if ($entity->get('status') !== 'Aprovado') {
            return;
        }

        $jornadaId = $entity->get('jornadaId');
        if (!$jornadaId) {
            return;
        }

        $unidadeId = $entity->get('unidadeId');
        if (!$unidadeId) {
            return;
        }

        // Find OrcamentoItems with tipoItem = 'InsumoExclusivo'
        $exclusiveItems = $this->findExclusiveItems($entity->getId());

        if (empty($exclusiveItems)) {
            return;
        }

        $teamsIds = $entity->getLinkMultipleIdList('teams');

        foreach ($exclusiveItems as $item) {
            $this->createExclusiveLote($item, $jornadaId, $unidadeId, $teamsIds);
        }
    }

    /**
     * @return Entity[]
     */
    private function findExclusiveItems(string $orcamentoId): array
    {
        $collection = $this->entityManager
            ->getRDBRepository('OrcamentoItem')
            ->where([
                'orcamentoId' => $orcamentoId,
                'tipoItem' => 'InsumoExclusivo',
            ])
            ->find();

        return is_array($collection) 
            ? $collection 
            : iterator_to_array($collection);
    }

    private function createExclusiveLote(
        Entity $item,
        string $jornadaId,
        string $unidadeId,
        array $teamsIds
    ): void {
        $insumoId = $item->get('insumoExclusivoId');
        if (!$insumoId) {
            return;
        }

        // Check if an exclusive lote already exists for this item/jornada
        $existingLote = $this->entityManager
            ->getRDBRepository('InsumoLote')
            ->where([
                'insumoId' => $insumoId,
                'jornadaId' => $jornadaId,
                'modalidadeUso' => 'Exclusivo',
            ])
            ->findOne();

        if ($existingLote) {
            return;
        }

        $quantidade = $item->get('quantidade') ?? 1;

        $lote = $this->entityManager->getNewEntity('InsumoLote');
        $lote->set([
            'insumoId' => $insumoId,
            'unidadeId' => $unidadeId,
            'jornadaId' => $jornadaId,
            'modalidadeUso' => 'Exclusivo',
            'statusUso' => 'Nova',
            'status' => 'Disponivel',
            'quantidadeAquisicao' => $quantidade,
            'numeroLote' => 'EXC-' . date('Ymd') . '-' . substr($jornadaId, 0, 8),
        ]);

        if (!empty($teamsIds)) {
            $lote->set('teamsIds', $teamsIds);
        }

        $this->entityManager->saveEntity($lote);
    }
}

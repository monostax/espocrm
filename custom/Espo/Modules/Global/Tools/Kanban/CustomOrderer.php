<?php
/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

namespace Espo\Modules\Global\Tools\Kanban;

use Espo\Core\ORM\EntityManager;
use Espo\Core\Utils\Id\RecordIdGenerator;
use Espo\Core\Utils\Metadata;
use Espo\Tools\Kanban\MetadataProvider;
use Espo\Tools\Kanban\Orderer as BaseOrderer;
use Espo\Tools\Kanban\OrdererProcessor;

/**
 * Custom Orderer that uses OpportunityOrdererProcessor for Opportunity
 * entity and falls back to default OrdererProcessor for other entities.
 */
class CustomOrderer extends BaseOrderer
{
    public function __construct(
        private EntityManager $entityManager,
        private Metadata $metadata,
        private RecordIdGenerator $idGenerator,
        private MetadataProvider $metadataProvider,
    ) {
        parent::__construct($entityManager, $metadata, $idGenerator, $metadataProvider);
    }

    public function setEntityType(string $entityType): OrdererProcessor
    {
        return $this->createProcessorForEntity($entityType)->setEntityType($entityType);
    }

    public function createProcessor(): OrdererProcessor
    {
        return new OrdererProcessor(
            $this->entityManager,
            $this->metadata,
            $this->idGenerator,
            $this->metadataProvider
        );
    }

    private function createProcessorForEntity(string $entityType): OrdererProcessor
    {
        if ($entityType === 'Opportunity') {
            return new OpportunityOrdererProcessor(
                $this->entityManager,
                $this->metadata,
                $this->idGenerator,
                $this->metadataProvider
            );
        }

        return $this->createProcessor();
    }
}

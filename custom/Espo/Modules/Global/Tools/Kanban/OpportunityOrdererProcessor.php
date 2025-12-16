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

use Espo\Core\Utils\Id\RecordIdGenerator;
use Espo\Entities\KanbanOrder;
use Espo\Core\ORM\EntityManager;
use Espo\Core\Utils\Metadata;
use Espo\ORM\Name\Attribute;
use Espo\Tools\Kanban\OrdererProcessor;
use LogicException;

/**
 * Custom OrdererProcessor for Opportunity that validates against OpportunityStage
 * records instead of static enum options.
 *
 * Extends OrdererProcessor to maintain type compatibility.
 */
class OpportunityOrdererProcessor extends OrdererProcessor
{
    private const MAX_GROUP_LENGTH = 100;
    private const DEFAULT_MAX_NUMBER = 50;

    private ?string $entityType = null;
    private ?string $group = null;
    private ?string $userId = null;

    private int $maxNumber = self::DEFAULT_MAX_NUMBER;

    public function __construct(
        private EntityManager $entityManager,
        private Metadata $metadata,
        private RecordIdGenerator $idGenerator
    ) {
        parent::__construct($entityManager, $metadata, $idGenerator);
    }

    public function setEntityType(string $entityType): self
    {
        $this->entityType = $entityType;

        return $this;
    }

    public function setGroup(string $group): self
    {
        $this->group = mb_substr($group, 0, self::MAX_GROUP_LENGTH);

        return $this;
    }

    public function setUserId(string $userId): self
    {
        $this->userId = $userId;

        return $this;
    }

    public function setMaxNumber(?int $maxNumber): self
    {
        if ($maxNumber === null) {
            $this->maxNumber = self::DEFAULT_MAX_NUMBER;

            return $this;
        }

        $this->maxNumber = $maxNumber;

        return $this;
    }

    /**
     * @param string[] $ids
     */
    public function order(array $ids): void
    {
        $this->validateCustom();

        $count = count($ids);

        if (!$count) {
            return;
        }

        $this->entityManager
            ->getTransactionManager()
            ->start();

        $deleteQuery1 = $this->entityManager
            ->getQueryBuilder()
            ->delete()
            ->from(KanbanOrder::ENTITY_TYPE)
            ->where([
                'entityType' => $this->entityType,
                'userId' => $this->userId,
                'entityId' => $ids,
            ])
            ->build();

        $this->entityManager->getQueryExecutor()->execute($deleteQuery1);

        $minOrder = null;

        $first = $this->entityManager
            ->getRDBRepository(KanbanOrder::ENTITY_TYPE)
            ->select([Attribute::ID, 'order'])
            ->where([
                'entityType' => $this->entityType,
                'userId' => $this->userId,
                'group' => $this->group,
            ])
            ->order('order')
            ->findOne();

        if ($first) {
            $minOrder = $first->get('order');
        }

        if ($minOrder !== null) {
            $offset = $count - $minOrder;

            $updateQuery = $this->entityManager
                ->getQueryBuilder()
                ->update()
                ->in(KanbanOrder::ENTITY_TYPE)
                ->where([
                    'entityType' => $this->entityType,
                    'group' => $this->group,
                    'userId' => $this->userId,
                ])
                ->set([
                    'order:' => 'ADD:(order, ' . strval($offset) . ')'
                ])
                ->build();

            $this->entityManager->getQueryExecutor()->execute($updateQuery);
        }

        $collection = $this->entityManager
            ->getCollectionFactory()
            ->create(KanbanOrder::ENTITY_TYPE);

        foreach ($ids as $i => $id) {
            $item = $this->entityManager->getNewEntity(KanbanOrder::ENTITY_TYPE);

            $item->set([
                'id' => $this->idGenerator->generate(),
                'entityId' => $id,
                'entityType' => $this->entityType,
                'group' => $this->group,
                'userId' => $this->userId,
                'order' => $i,
            ]);

            $collection[] = $item;
        }

        $this->entityManager->getMapper()->massInsert($collection);

        $deleteQuery2 = $this->entityManager
            ->getQueryBuilder()
            ->delete()
            ->from(KanbanOrder::ENTITY_TYPE)
            ->where([
                'entityType' => $this->entityType,
                'group' => $this->group,
                'userId' => $this->userId,
                'order>' => $this->maxNumber,
            ])
            ->build();

        $this->entityManager->getQueryExecutor()->execute($deleteQuery2);

        $this->entityManager
            ->getTransactionManager()
            ->commit();
    }

    private function validateCustom(): void
    {
        if (!$this->entityType) {
            throw new LogicException("No entity type.");
        }

        if (!$this->group) {
            throw new LogicException("No group.");
        }

        if (!$this->userId) {
            throw new LogicException("No user ID.");
        }

        if (!$this->metadata->get(['scopes', $this->entityType, 'object'])) {
            throw new LogicException("Not allowed entity type.");
        }

        $orderDisabled = $this->metadata->get(['scopes', $this->entityType, 'kanbanOrderDisabled']);

        if ($orderDisabled) {
            throw new LogicException("Order is disabled.");
        }

        // For Opportunity, validate against OpportunityStage records
        if ($this->entityType === 'Opportunity') {
            $this->validateOpportunityStage();
            return;
        }

        // Default validation for other entities
        $statusField = $this->metadata->get(['scopes', $this->entityType, 'statusField']);

        if (!$statusField) {
            throw new LogicException("No status field.");
        }

        $statusList = $this->metadata
            ->get(['entityDefs', $this->entityType, 'fields', $statusField, 'options']) ?? [];

        if (!in_array($this->group, $statusList)) {
            throw new LogicException("Group is not available in status list.");
        }
    }

    /**
     * Validate that the group is a valid OpportunityStage ID.
     */
    private function validateOpportunityStage(): void
    {
        $stage = $this->entityManager
            ->getRDBRepository('OpportunityStage')
            ->select([Attribute::ID])
            ->where([
                'id' => $this->group,
            ])
            ->findOne();

        if (!$stage) {
            throw new LogicException("OpportunityStage with ID '{$this->group}' does not exist.");
        }
    }
}

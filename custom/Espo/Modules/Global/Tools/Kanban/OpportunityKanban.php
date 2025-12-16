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

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Error;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\FieldProcessing\ListLoadProcessor;
use Espo\Core\FieldProcessing\Loader\Params as FieldLoaderParams;
use Espo\Core\Record\Collection;
use Espo\Core\Record\Select\ApplierClassNameListProvider;
use Espo\Core\Record\ServiceContainer as RecordServiceContainer;
use Espo\Core\Select\SearchParams;
use Espo\Core\Select\SelectBuilderFactory;
use Espo\Core\Utils\Metadata;
use Espo\Entities\User;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\Tools\Kanban\GroupItem;
use Espo\Tools\Kanban\Result;

/**
 * Custom Kanban handler for Opportunity entity that uses OpportunityStage
 * link field instead of a static enum. Supports funnel-scoped views.
 */
class OpportunityKanban
{
    private const DEFAULT_MAX_ORDER_NUMBER = 50;
    private const MAX_GROUP_LENGTH = 100;

    private ?string $entityType = null;
    private bool $countDisabled = false;
    private bool $orderDisabled = false;
    private ?SearchParams $searchParams = null;
    private ?string $userId = null;
    private int $maxOrderNumber = self::DEFAULT_MAX_ORDER_NUMBER;
    private ?string $funnelId = null;

    public function __construct(
        private Metadata $metadata,
        private SelectBuilderFactory $selectBuilderFactory,
        private EntityManager $entityManager,
        private ListLoadProcessor $listLoadProcessor,
        private RecordServiceContainer $recordServiceContainer,
        private ApplierClassNameListProvider $applierClassNameListProvider,
        private User $user,
    ) {}

    public function setEntityType(string $entityType): self
    {
        $this->entityType = $entityType;

        return $this;
    }

    public function setSearchParams(SearchParams $searchParams): self
    {
        $this->searchParams = $searchParams;

        return $this;
    }

    public function setCountDisabled(bool $countDisabled): self
    {
        $this->countDisabled = $countDisabled;

        return $this;
    }

    public function setOrderDisabled(bool $orderDisabled): self
    {
        $this->orderDisabled = $orderDisabled;

        return $this;
    }

    public function setUserId(string $userId): self
    {
        $this->userId = $userId;

        return $this;
    }

    public function setMaxOrderNumber(?int $maxOrderNumber): self
    {
        if ($maxOrderNumber === null) {
            $this->maxOrderNumber = self::DEFAULT_MAX_ORDER_NUMBER;

            return $this;
        }

        $this->maxOrderNumber = $maxOrderNumber;

        return $this;
    }

    public function setFunnelId(?string $funnelId): self
    {
        $this->funnelId = $funnelId;

        return $this;
    }

    /**
     * Get kanban record data.
     *
     * @throws Error
     * @throws Forbidden
     * @throws BadRequest
     */
    public function getResult(): Result
    {
        if (!$this->entityType) {
            throw new Error("Entity type is not specified.");
        }

        if (!$this->searchParams) {
            throw new Error("No search params.");
        }

        // If no funnel is specified, try to get the default one
        if (!$this->funnelId) {
            $this->funnelId = $this->getDefaultFunnelId();
        }

        if (!$this->funnelId) {
            throw new BadRequest("No funnel specified and no default funnel available. Please select a funnel.");
        }

        $searchParams = $this->searchParams;

        $recordService = $this->recordServiceContainer->get($this->entityType);

        $maxSize = $searchParams->getMaxSize();

        if ($this->countDisabled && $maxSize) {
            $searchParams = $searchParams->withMaxSize($maxSize + 1);
        }

        $query = $this->selectBuilderFactory
            ->create()
            ->from($this->entityType)
            ->withStrictAccessControl()
            ->withSearchParams($searchParams)
            ->withAdditionalApplierClassNameList(
                $this->applierClassNameListProvider->get($this->entityType)
            )
            ->build();

        // Filter by funnel
        $query = $this->entityManager
            ->getQueryBuilder()
            ->select()
            ->clone($query)
            ->where(['funnelId' => $this->funnelId])
            ->build();

        // Use opportunityStageId as the status field for link-based status
        $statusField = 'opportunityStageId';
        $statusList = $this->getOpportunityStageList();
        $statusIgnoreList = $this->getStatusIgnoreList();

        $groupList = [];

        $repository = $this->entityManager->getRDBRepository($this->entityType);

        $hasMore = false;

        foreach ($statusList as $stageData) {
            $stageId = $stageData['id'];
            $stageName = $stageData['name'];
            $stageStyle = $stageData['style'] ?? null;

            if (in_array($stageId, $statusIgnoreList)) {
                continue;
            }

            if (!$stageId) {
                continue;
            }

            $itemSelectBuilder = $this->entityManager
                ->getQueryBuilder()
                ->select()
                ->clone($query);

            $itemSelectBuilder->where([
                $statusField => $stageId,
            ]);

            $itemQuery = $itemSelectBuilder->build();

            $newOrder = $itemQuery->getOrder();

            array_unshift($newOrder, [
                'COALESCE:(kanbanOrder.order, ' . ($this->maxOrderNumber + 1) . ')',
                'ASC',
            ]);

            if ($this->userId && !$this->orderDisabled) {
                $group = mb_substr($stageId, 0, self::MAX_GROUP_LENGTH);

                $itemQuery = $this->entityManager
                    ->getQueryBuilder()
                    ->select()
                    ->clone($itemQuery)
                    ->order($newOrder)
                    ->leftJoin(
                        'KanbanOrder',
                        'kanbanOrder',
                        [
                            'kanbanOrder.entityType' => $this->entityType,
                            'kanbanOrder.entityId:' => 'id',
                            'kanbanOrder.group' => $group,
                            'kanbanOrder.userId' => $this->userId,
                        ]
                    )
                    ->build();
            }

            $collectionSub = $repository
                ->clone($itemQuery)
                ->find();

            if (!$this->countDisabled) {
                $totalSub = $repository->clone($itemQuery)->count();
            } else {
                $recordCollection = Collection::createNoCount($collectionSub, $maxSize);

                $collectionSub = $recordCollection->getCollection();
                $totalSub = $recordCollection->getTotal();

                if ($totalSub === Collection::TOTAL_HAS_MORE) {
                    $hasMore = true;
                }
            }

            $loadProcessorParams = FieldLoaderParams
                ::create()
                ->withSelect($searchParams->getSelect());

            foreach ($collectionSub as $e) {
                $this->listLoadProcessor->process($e, $loadProcessorParams);

                $recordService->prepareEntityForOutput($e);
            }

            /** @var Collection<Entity> $itemRecordCollection */
            $itemRecordCollection = new Collection($collectionSub, $totalSub);

            // Use stage ID as name (for filtering) and stage name as label (for display)
            $groupList[] = new GroupItem($stageId, $itemRecordCollection, $stageName, $stageStyle);
        }

        $total = !$this->countDisabled ?
            $repository->clone($query)->count() :
            ($hasMore ? Collection::TOTAL_HAS_MORE : Collection::TOTAL_HAS_NO_MORE);

        return new Result($groupList, $total);
    }

    /**
     * Get the current funnel ID.
     */
    public function getFunnelId(): ?string
    {
        return $this->funnelId;
    }

    /**
     * Get the list of OpportunityStage records for the current funnel.
     * Respects ACL - only returns stages the user has access to.
     *
     * @return array<int, array{id: string, name: string, style: ?string}>
     * @throws Error
     */
    private function getOpportunityStageList(): array
    {
        if (!$this->funnelId) {
            throw new Error("Funnel ID is required to get opportunity stages.");
        }

        // Using warning level to ensure visibility in logs
        $GLOBALS['log']->warning(
            "[OpportunityKanban] getOpportunityStageList() funnelId=" . ($this->funnelId ?? 'NULL')
        );

        // Build query with ACL protection
        $query = $this->selectBuilderFactory
            ->create()
            ->from('OpportunityStage')
            ->withStrictAccessControl()
            ->withSearchParams(
                SearchParams::create()
                    ->withWhere(
                        \Espo\Core\Select\Where\Item::createBuilder()
                            ->setAttribute('funnelId')
                            ->setType('equals')
                            ->setValue($this->funnelId)
                            ->build()
                    )
            )
            ->build();

        // Add isActive filter and ordering
        $query = $this->entityManager
            ->getQueryBuilder()
            ->select()
            ->clone($query)
            ->where(['isActive' => true])
            ->order('order', 'ASC')
            ->build();

        $stages = $this->entityManager
            ->getRDBRepository('OpportunityStage')
            ->clone($query)
            ->find();

        $list = [];

        foreach ($stages as $stage) {
            $list[] = [
                'id' => $stage->getId(),
                'name' => $stage->get('name'),
                'style' => $stage->get('style'),
            ];
        }

        $stageIds = implode(',', array_column($list, 'id'));
        $stageNames = implode(',', array_column($list, 'name'));
        $GLOBALS['log']->warning(
            "[OpportunityKanban] getOpportunityStageList() result funnelId=" . ($this->funnelId ?? 'NULL') .
            " stageCount=" . count($list) .
            " stageIds=[{$stageIds}]" .
            " stageNames=[{$stageNames}]"
        );

        if (empty($list)) {
            throw new Error("No active OpportunityStage records found for the selected funnel.");
        }

        return $list;
    }

    /**
     * Get the default funnel ID for the current user.
     * Respects ACL - only considers funnels the user has access to.
     */
    private function getDefaultFunnelId(): ?string
    {
        // Build query with ACL protection for default funnel
        $defaultQuery = $this->selectBuilderFactory
            ->create()
            ->from('Funnel')
            ->withStrictAccessControl()
            ->build();

        $defaultQuery = $this->entityManager
            ->getQueryBuilder()
            ->select()
            ->clone($defaultQuery)
            ->where([
                'isDefault' => true,
                'isActive' => true,
            ])
            ->build();

        $defaultFunnel = $this->entityManager
            ->getRDBRepository('Funnel')
            ->clone($defaultQuery)
            ->findOne();

        if ($defaultFunnel) {
            return $defaultFunnel->getId();
        }

        // Fall back to first active funnel the user has access to
        $fallbackQuery = $this->selectBuilderFactory
            ->create()
            ->from('Funnel')
            ->withStrictAccessControl()
            ->build();

        $fallbackQuery = $this->entityManager
            ->getQueryBuilder()
            ->select()
            ->clone($fallbackQuery)
            ->where(['isActive' => true])
            ->order('name', 'ASC')
            ->build();

        $firstFunnel = $this->entityManager
            ->getRDBRepository('Funnel')
            ->clone($fallbackQuery)
            ->findOne();

        return $firstFunnel?->getId();
    }

    /**
     * @return string[]
     */
    private function getStatusIgnoreList(): array
    {
        assert(is_string($this->entityType));

        return $this->metadata->get(['scopes', $this->entityType, 'kanbanStatusIgnoreList'], []);
    }
}

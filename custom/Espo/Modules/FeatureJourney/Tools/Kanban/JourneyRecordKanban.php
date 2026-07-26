<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureJourney\Tools\Kanban;

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
use Espo\Core\Select\Where\Item as WhereItem;
use Espo\Core\Utils\Metadata;
use Espo\Entities\User;
use Espo\Modules\FeatureJourney\Entities\Journey;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\Tools\Kanban\GroupItem;
use Espo\Tools\Kanban\Result;

/**
 * Kanban grouped by JourneyStage (currentStageId), scoped to one Journey.
 * Read-only board: stage moves must go through TransitionExecutor (manual trigger).
 */
class JourneyRecordKanban
{
    private const DEFAULT_MAX_ORDER_NUMBER = 50;
    private const MAX_GROUP_LENGTH = 100;

    private ?string $entityType = null;
    private bool $countDisabled = false;
    private bool $orderDisabled = true;
    private ?SearchParams $searchParams = null;
    private ?string $userId = null;
    private int $maxOrderNumber = self::DEFAULT_MAX_ORDER_NUMBER;
    private ?string $journeyId = null;

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
        $this->maxOrderNumber = $maxOrderNumber ?? self::DEFAULT_MAX_ORDER_NUMBER;

        return $this;
    }

    public function setJourneyId(?string $journeyId): self
    {
        $this->journeyId = $journeyId;

        return $this;
    }

    public function getJourneyId(): ?string
    {
        return $this->journeyId;
    }

    /**
     * @throws Error
     * @throws Forbidden
     * @throws BadRequest
     */
    public function getResult(): Result
    {
        if (!$this->entityType) {
            throw new Error('Entity type is not specified.');
        }

        if (!$this->searchParams) {
            throw new Error('No search params.');
        }

        if (!$this->journeyId) {
            $this->journeyId = $this->extractJourneyId($this->searchParams);
        }

        if (!$this->journeyId) {
            $this->journeyId = $this->getDefaultJourneyId();
        }

        if (!$this->journeyId) {
            throw new BadRequest(
                'No journey specified and no accessible journey available. Please select a journey.'
            );
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

        $query = $this->entityManager
            ->getQueryBuilder()
            ->select()
            ->clone($query)
            ->where(['journeyId' => $this->journeyId])
            ->build();

        $statusField = 'currentStageId';
        $statusList = $this->getStageList();
        $statusIgnoreList = $this->getStatusIgnoreList();

        $groupList = [];
        $repository = $this->entityManager->getRDBRepository($this->entityType);
        $hasMore = false;

        foreach ($statusList as $stageData) {
            $stageId = $stageData['id'];
            $stageName = $stageData['name'];
            $stageStyle = $stageData['style'] ?? null;

            if (in_array($stageId, $statusIgnoreList, true) || !$stageId) {
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

            $collectionSub = $repository->clone($itemQuery)->find();

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

            $loadProcessorParams = FieldLoaderParams::create()
                ->withSelect($searchParams->getSelect());

            foreach ($collectionSub as $e) {
                $this->listLoadProcessor->process($e, $loadProcessorParams);
                $recordService->prepareEntityForOutput($e);
            }

            /** @var Collection<Entity> $itemRecordCollection */
            $itemRecordCollection = new Collection($collectionSub, $totalSub);

            $groupList[] = new GroupItem($stageId, $itemRecordCollection, $stageName, $stageStyle);
        }

        $total = !$this->countDisabled
            ? $repository->clone($query)->count()
            : ($hasMore ? Collection::TOTAL_HAS_MORE : Collection::TOTAL_HAS_NO_MORE);

        return new Result($groupList, $total);
    }

    /**
     * @return list<array{id: string, name: string, style: ?string}>
     * @throws Error
     */
    private function getStageList(): array
    {
        if (!$this->journeyId) {
            throw new Error('Journey ID is required to get stages.');
        }

        $query = $this->selectBuilderFactory
            ->create()
            ->from('JourneyStage')
            ->withStrictAccessControl()
            ->withSearchParams(
                SearchParams::create()
                    ->withWhere(
                        WhereItem::createBuilder()
                            ->setAttribute('journeyId')
                            ->setType('equals')
                            ->setValue($this->journeyId)
                            ->build()
                    )
            )
            ->build();

        $query = $this->entityManager
            ->getQueryBuilder()
            ->select()
            ->clone($query)
            ->where(['isActive' => true])
            ->order('order', 'ASC')
            ->build();

        $stages = $this->entityManager
            ->getRDBRepository('JourneyStage')
            ->clone($query)
            ->find();
        $list = [];

        foreach ($stages as $stage) {
            $list[] = [
                'id' => $stage->getId(),
                'name' => (string) $stage->get('name'),
                'style' => $stage->get('style') ? (string) $stage->get('style') : null,
            ];
        }

        if ($list === []) {
            throw new Error('No active JourneyStage records found for the selected journey.');
        }

        return $list;
    }

    private function getDefaultJourneyId(): ?string
    {
        $activeQuery = $this->selectBuilderFactory
            ->create()
            ->from(Journey::ENTITY_TYPE)
            ->withStrictAccessControl()
            ->build();

        $activeQuery = $this->entityManager
            ->getQueryBuilder()
            ->select()
            ->clone($activeQuery)
            ->where(['status' => Journey::STATUS_ACTIVE])
            ->order('name', 'ASC')
            ->build();

        $active = $this->entityManager
            ->getRDBRepository(Journey::ENTITY_TYPE)
            ->clone($activeQuery)
            ->findOne();

        if ($active) {
            return $active->getId();
        }

        $anyQuery = $this->selectBuilderFactory
            ->create()
            ->from(Journey::ENTITY_TYPE)
            ->withStrictAccessControl()
            ->build();

        $anyQuery = $this->entityManager
            ->getQueryBuilder()
            ->select()
            ->clone($anyQuery)
            ->where(['status!=' => Journey::STATUS_ARCHIVED])
            ->order('name', 'ASC')
            ->build();

        $any = $this->entityManager
            ->getRDBRepository(Journey::ENTITY_TYPE)
            ->clone($anyQuery)
            ->findOne();

        return $any?->getId();
    }

    private function extractJourneyId(SearchParams $searchParams): ?string
    {
        $whereClause = $searchParams->getWhere();

        if (!$whereClause) {
            return null;
        }

        return $this->findJourneyIdInWhere($whereClause->getRaw());
    }

    /**
     * @param array<string, mixed> $whereRaw
     */
    private function findJourneyIdInWhere(array $whereRaw): ?string
    {
        if (isset($whereRaw['type']) && isset($whereRaw['value']) && is_array($whereRaw['value'])) {
            $attribute = $whereRaw['attribute'] ?? null;
            $field = $whereRaw['field'] ?? null;

            if (
                $attribute === 'journeyId' || $attribute === 'journey' ||
                $field === 'journeyId' || $field === 'journey'
            ) {
                $value = $whereRaw['value'] ?? null;

                if (is_string($value) && $value !== '') {
                    return $value;
                }
            }

            return $this->findJourneyIdInWhere($whereRaw['value']);
        }

        foreach ($whereRaw as $item) {
            if (!is_array($item)) {
                continue;
            }

            $attribute = $item['attribute'] ?? null;
            $field = $item['field'] ?? null;

            if (
                $attribute === 'journeyId' || $attribute === 'journey' ||
                $field === 'journeyId' || $field === 'journey'
            ) {
                $value = $item['value'] ?? null;

                if (is_string($value) && $value !== '') {
                    return $value;
                }
            }

            if (isset($item['value']) && is_array($item['value'])) {
                $nested = $this->findJourneyIdInWhere($item['value']);

                if ($nested) {
                    return $nested;
                }
            }
        }

        return null;
    }

    /**
     * @return string[]
     */
    private function getStatusIgnoreList(): array
    {
        assert(is_string($this->entityType));

        return $this->metadata->get(['scopes', $this->entityType, 'kanbanStatusIgnoreList'], []) ?? [];
    }
}

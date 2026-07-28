<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureJourney\Services;

use Espo\Core\Job\JobSchedulerFactory;
use Espo\Core\Utils\DataCache;
use Espo\Core\Utils\Log;
use Espo\Modules\FeatureJourney\Entities\Journey;
use Espo\Modules\FeatureJourney\Entities\JourneyRecord;
use Espo\Modules\FeatureJourney\Entities\JourneyTransition;
use Espo\Modules\FeatureJourney\Jobs\ProcessJourneyTransition;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

class EntityChangeDispatcher
{
    /** @var array<string, bool> */
    private static array $requestCache = [];

    public function __construct(
        private EntityManager $entityManager,
        private TenantResolver $tenantResolver,
        private JobSchedulerFactory $jobSchedulerFactory,
        private DataCache $dataCache,
        private Log $log,
    ) {}

    public function dispatch(Entity $entity): void
    {
        if (!empty($GLOBALS['__journey_skip_dispatch'])) {
            return;
        }

        $entityType = $entity->getEntityType();
        $tenantId = $this->tenantResolver->resolveTenantIdForEntity($entity);

        if (!$tenantId) {
            return;
        }

        if (!$this->hasListeners($entityType, $tenantId)) {
            return;
        }

        $records = $this->entityManager
            ->getRDBRepository(JourneyRecord::ENTITY_TYPE)
            ->where([
                'tenantId' => $tenantId,
                'status' => JourneyRecord::STATUS_ACTIVE,
                'targetType' => $entityType,
                'targetId' => $entity->getId(),
            ])
            ->limit(0, 100)
            ->find();

        foreach ($records as $record) {
            $transitions = $this->entityManager
                ->getRDBRepository(JourneyTransition::ENTITY_TYPE)
                ->where([
                    'journeyId' => $record->get('journeyId'),
                    'isActive' => true,
                ])
                ->order('priority', 'ASC')
                ->find();

            $candidates = [];

            foreach ($transitions as $transition) {
                if (!JourneyTransition::appliesToStage($transition, (string) $record->get('currentStageId'))) {
                    continue;
                }

                if (!JourneyTransition::entityWakesOn($transition, JourneyTransition::TRIGGER_ENTITY_CHANGE)) {
                    continue;
                }

                $candidates[] = $transition;
            }

            if ($candidates === []) {
                continue;
            }

            usort($candidates, [JourneyTransition::class, 'compareForRecord']);
            $this->queue(
                (string) $record->getId(),
                array_map(
                    static fn (Entity $transition): string => (string) $transition->getId(),
                    $candidates,
                ),
            );
        }
    }

    private function hasListeners(string $entityType, string $tenantId): bool
    {
        $key = $entityType . '|' . $tenantId;

        if (array_key_exists($key, self::$requestCache)) {
            return self::$requestCache[$key];
        }

        $cacheKey = 'journeyEntityChange/' . md5($key);

        try {
            $cached = $this->dataCache->tryGet($cacheKey);
            if (is_array($cached) && isset($cached['v'])) {
                self::$requestCache[$key] = (bool) $cached['v'];

                return self::$requestCache[$key];
            }
        } catch (\Throwable) {
        }

        $journeys = $this->entityManager
            ->getRDBRepository(Journey::ENTITY_TYPE)
            ->where([
                'tenantId' => $tenantId,
                'status' => Journey::STATUS_ACTIVE,
            ])
            ->find();

        $has = false;

        foreach ($journeys as $journey) {
            $candidates = $this->entityManager
                ->getRDBRepository(JourneyTransition::ENTITY_TYPE)
                ->where([
                    'journeyId' => $journey->getId(),
                    'isActive' => true,
                ])
                ->limit(0, 200)
                ->find();

            foreach ($candidates as $t) {
                if (JourneyTransition::entityWakesOn($t, JourneyTransition::TRIGGER_ENTITY_CHANGE)) {
                    $has = true;
                    break 2;
                }
            }
        }

        self::$requestCache[$key] = $has;

        try {
            $this->dataCache->store($cacheKey, ['v' => $has]);
        } catch (\Throwable) {
        }

        return $has;
    }

    /**
     * @param non-empty-list<string> $transitionIds
     */
    private function queue(string $recordId, array $transitionIds): void
    {
        try {
            $this->jobSchedulerFactory
                ->create()
                ->setClassName(ProcessJourneyTransition::class)
                ->setData([
                    'journeyRecordId' => $recordId,
                    'transitionId' => $transitionIds[0],
                    'transitionIds' => $transitionIds,
                    'signal' => null,
                    'firedBy' => 'entityChange',
                ])
                ->schedule();
        } catch (\Throwable $e) {
            $this->log->error('EntityChangeDispatcher: ' . $e->getMessage());
        }
    }
}

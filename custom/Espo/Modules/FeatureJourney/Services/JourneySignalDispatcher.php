<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureJourney\Services;

use Espo\Core\Job\JobSchedulerFactory;
use Espo\Core\Utils\Log;
use Espo\Modules\FeatureJourney\Entities\Journey;
use Espo\Modules\FeatureJourney\Entities\JourneyRecord;
use Espo\Modules\FeatureJourney\Entities\JourneyTransition;
use Espo\Modules\FeatureJourney\Jobs\ProcessJourneyTransition;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

class JourneySignalDispatcher
{
    public function __construct(
        private EntityManager $entityManager,
        private JobSchedulerFactory $jobSchedulerFactory,
        private Log $log,
    ) {}

    /**
     * @param array<string, mixed> $payload
     */
    public function dispatch(
        string $tenantId,
        string $code,
        ?Entity $targetEntity = null,
        array $payload = [],
        ?string $eventId = null,
    ): void {
        if ($tenantId === '' || $code === '') {
            return;
        }

        $signal = [
            'code' => $code,
            'payload' => $payload,
            'eventId' => $eventId,
        ];

        $goalRecordIds = $this->dispatchGoalFastPath($tenantId, $code, $targetEntity, $signal);
        $this->dispatchToActiveRecords($tenantId, $code, $targetEntity, $signal, $goalRecordIds);
        $this->dispatchEnrollmentRules($tenantId, $code, $targetEntity, $signal);
    }

    /**
     * @param array{code: string, payload: array<string, mixed>, eventId?: ?string} $signal
     * @param list<string> $skipRecordIds
     */
    private function dispatchToActiveRecords(
        string $tenantId,
        string $code,
        ?Entity $targetEntity,
        array $signal,
        array $skipRecordIds = [],
    ): void {
        $where = [
            'tenantId' => $tenantId,
            'status' => JourneyRecord::STATUS_ACTIVE,
        ];

        if ($targetEntity) {
            $where['targetType'] = $targetEntity->getEntityType();
            $where['targetId'] = $targetEntity->getId();
        }

        $records = $this->entityManager
            ->getRDBRepository(JourneyRecord::ENTITY_TYPE)
            ->where($where)
            ->limit(0, 500)
            ->find();

        foreach ($records as $record) {
            if (in_array((string) $record->getId(), $skipRecordIds, true)) {
                continue;
            }

            $stageId = $record->get('currentStageId');
            $journeyId = $record->get('journeyId');

            if (!$stageId || !$journeyId) {
                continue;
            }

            $journey = $this->entityManager->getEntityById(Journey::ENTITY_TYPE, (string) $journeyId);
            if (!$journey || $journey->get('status') !== Journey::STATUS_ACTIVE) {
                continue;
            }

            $transitions = $this->entityManager
                ->getRDBRepository(JourneyTransition::ENTITY_TYPE)
                ->where([
                    'journeyId' => $journeyId,
                    'isActive' => true,
                ])
                ->order('priority', 'ASC')
                ->find();

            $candidates = [];

            foreach ($transitions as $transition) {
                if (!JourneyTransition::appliesToStage($transition, (string) $stageId)) {
                    continue;
                }

                if (!JourneyTransition::entityWakesOn($transition, JourneyTransition::TRIGGER_SIGNAL)) {
                    continue;
                }

                $codes = $transition->get('eventCodes') ?: [];
                if (!is_array($codes) || !in_array($code, $codes, true)) {
                    continue;
                }

                $candidates[] = $transition;
            }

            if ($candidates === []) {
                continue;
            }

            usort($candidates, [JourneyTransition::class, 'compareForRecord']);
            $transitionIds = array_map(
                static fn (Entity $transition): string => (string) $transition->getId(),
                $candidates,
            );

            $this->queueTransition(
                (string) $record->getId(),
                $transitionIds[0],
                $signal,
                $transitionIds,
            );
        }
    }

    /**
     * @param array{code: string, payload: array<string, mixed>, eventId?: ?string} $signal
     * @return list<string>
     */
    private function dispatchGoalFastPath(
        string $tenantId,
        string $code,
        ?Entity $targetEntity,
        array $signal,
    ): array {
        if (!$targetEntity) {
            return [];
        }

        $queuedRecordIds = [];

        $records = $this->entityManager
            ->getRDBRepository(JourneyRecord::ENTITY_TYPE)
            ->where([
                'tenantId' => $tenantId,
                'status' => JourneyRecord::STATUS_ACTIVE,
                'targetType' => $targetEntity->getEntityType(),
                'targetId' => $targetEntity->getId(),
            ])
            ->limit(0, 100)
            ->find();

        foreach ($records as $record) {
            $journey = $this->entityManager->getEntityById(
                Journey::ENTITY_TYPE,
                (string) $record->get('journeyId')
            );

            if (!$journey || $journey->get('status') !== Journey::STATUS_ACTIVE) {
                continue;
            }

            $goalCodes = $journey->get('goalEventCodes') ?: [];
            if (!is_array($goalCodes) || !in_array($code, $goalCodes, true)) {
                continue;
            }

            $recordId = (string) $record->getId();
            if ($this->queueTransition($recordId, '__goal__', $signal)) {
                $queuedRecordIds[] = $recordId;
            }
        }

        return $queuedRecordIds;
    }

    /**
     * @param array{code: string, payload: array<string, mixed>, eventId?: ?string} $signal
     */
    private function dispatchEnrollmentRules(
        string $tenantId,
        string $code,
        ?Entity $targetEntity,
        array $signal,
    ): void {
        if (!$targetEntity) {
            return;
        }

        $journeys = $this->entityManager
            ->getRDBRepository(Journey::ENTITY_TYPE)
            ->where([
                'tenantId' => $tenantId,
                'status' => Journey::STATUS_ACTIVE,
                'targetEntityType' => $targetEntity->getEntityType(),
            ])
            ->find();

        foreach ($journeys as $journey) {
            $transitions = $this->entityManager
                ->getRDBRepository(JourneyTransition::ENTITY_TYPE)
                ->where([
                    'journeyId' => $journey->getId(),
                    'fromStageId' => null,
                    'isActive' => true,
                ])
                ->order('priority', 'ASC')
                ->find();

            foreach ($transitions as $transition) {
                if (JourneyTransition::resolveScope($transition) !== JourneyTransition::SCOPE_ENROLLMENT) {
                    continue;
                }

                if (!JourneyTransition::entityWakesOn($transition, JourneyTransition::TRIGGER_SIGNAL)) {
                    continue;
                }

                $codes = $transition->get('eventCodes') ?: [];
                if (!is_array($codes) || !in_array($code, $codes, true)) {
                    continue;
                }

                // Enroll then transition — enrollment service used by ProcessJourneyTransition
                // when record does not yet exist. Queue with target reference in signal.
                $enriched = $signal + [
                    'enrollTargetType' => $targetEntity->getEntityType(),
                    'enrollTargetId' => $targetEntity->getId(),
                    'journeyId' => $journey->getId(),
                ];
                $this->queueTransition('', (string) $transition->getId(), $enriched);
                break;
            }
        }
    }

    /**
     * @param array<string, mixed> $signal
     * @param list<string> $transitionIds
     */
    private function queueTransition(
        string $recordId,
        string $transitionId,
        array $signal,
        array $transitionIds = [],
    ): bool
    {
        try {
            $data = [
                'journeyRecordId' => $recordId,
                'transitionId' => $transitionId,
                'signal' => $signal,
            ];

            if ($transitionIds !== []) {
                $data['transitionIds'] = $transitionIds;
            }

            $this->jobSchedulerFactory
                ->create()
                ->setClassName(ProcessJourneyTransition::class)
                ->setData($data)
                ->schedule();

            return true;
        } catch (\Throwable $e) {
            $this->log->error('JourneySignalDispatcher: failed to queue: ' . $e->getMessage());

            return false;
        }
    }
}

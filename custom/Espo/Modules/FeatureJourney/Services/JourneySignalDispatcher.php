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

        $this->dispatchToActiveRecords($tenantId, $code, $targetEntity, $signal);
        $this->dispatchGoalFastPath($tenantId, $code, $targetEntity, $signal);
        $this->dispatchEnrollmentRules($tenantId, $code, $targetEntity, $signal);
    }

    /**
     * @param array{code: string, payload: array<string, mixed>, eventId?: ?string} $signal
     */
    private function dispatchToActiveRecords(
        string $tenantId,
        string $code,
        ?Entity $targetEntity,
        array $signal,
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
                    'fromStageId' => $stageId,
                    'isActive' => true,
                ])
                ->order('priority', 'ASC')
                ->find();

            foreach ($transitions as $transition) {
                if (!JourneyTransition::entityWakesOn($transition, JourneyTransition::TRIGGER_SIGNAL)) {
                    continue;
                }

                $codes = $transition->get('eventCodes') ?: [];
                if (!is_array($codes) || !in_array($code, $codes, true)) {
                    continue;
                }

                $this->queueTransition((string) $record->getId(), (string) $transition->getId(), $signal);
                break; // first-match-wins per record
            }
        }
    }

    /**
     * @param array{code: string, payload: array<string, mixed>, eventId?: ?string} $signal
     */
    private function dispatchGoalFastPath(
        string $tenantId,
        string $code,
        ?Entity $targetEntity,
        array $signal,
    ): void {
        // Goal codes are handled inside TransitionExecutor after transitions;
        // also queue a no-op-transition goal check via ProcessJourneyTransition
        // when a record's journey lists the code. Cheapest path is already covered
        // when a matching stage transition fires. Standalone goal check:
        if (!$targetEntity) {
            return;
        }

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

            // Queue with transitionId empty sentinel handled differently — use dedicated goal marker
            $this->queueTransition((string) $record->getId(), '__goal__', $signal);
        }
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
     */
    private function queueTransition(string $recordId, string $transitionId, array $signal): void
    {
        try {
            $this->jobSchedulerFactory
                ->create()
                ->setClassName(ProcessJourneyTransition::class)
                ->setData([
                    'journeyRecordId' => $recordId,
                    'transitionId' => $transitionId,
                    'signal' => $signal,
                ])
                ->schedule();
        } catch (\Throwable $e) {
            $this->log->error('JourneySignalDispatcher: failed to queue: ' . $e->getMessage());
        }
    }
}

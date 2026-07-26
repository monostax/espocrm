<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureJourney\Services;

use Espo\Core\Utils\Log;
use Espo\Modules\FeatureJourney\Entities\Journey;
use Espo\Modules\FeatureJourney\Entities\JourneyRecord;
use Espo\Modules\FeatureJourney\Entities\JourneyRecordLog;
use Espo\Modules\FeatureJourney\Entities\JourneyStage;
use Espo\Modules\FeatureJourney\Entities\JourneyStageAction;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\Core\ORM\Repository\Option\SaveOption;
use Throwable;

/**
 * Idempotent claim-based transition executor.
 */
class TransitionExecutor
{
    private const MAX_RETRIES = 3;
    private const SKIP_OPT = 'skipJourneyDispatch';

    public function __construct(
        private EntityManager $entityManager,
        private TransitionEvaluator $evaluator,
        private ActionRunner $actionRunner,
        private JourneyLifecycleEmitter $lifecycleEmitter,
        private TenantGuard $tenantGuard,
        private Log $log,
    ) {}

    /**
     * @param array{code?: string, payload?: array<string, mixed>, eventId?: string}|null $signal
     */
    public function execute(string $recordId, string $transitionId, ?array $signal = null, string $firedBy = 'system'): bool
    {
        $record = $this->entityManager->getEntityById(JourneyRecord::ENTITY_TYPE, $recordId);

        if (!$record) {
            $this->log->warning("TransitionExecutor: record {$recordId} not found");

            return false;
        }

        $transition = $this->entityManager->getEntityById('JourneyTransition', $transitionId);

        if (!$transition || !$transition->get('isActive')) {
            return false;
        }

        if ($record->get('status') !== JourneyRecord::STATUS_ACTIVE) {
            return false;
        }

        $fromStageId = $transition->get('fromStageId');
        $currentStageId = $record->get('currentStageId');

        if ($fromStageId && $fromStageId !== $currentStageId) {
            return false;
        }

        if (!$this->claim($record)) {
            return false;
        }

        // re-read after claim
        $record = $this->entityManager->getEntityById(JourneyRecord::ENTITY_TYPE, $recordId);

        if (!$record) {
            return false;
        }

        try {
            $evalSignal = $signal;
            if ($evalSignal === null) {
                $evalSignal = ['firedBy' => $firedBy];
            } else {
                $evalSignal['firedBy'] = $firedBy;
            }

            if (!$this->evaluator->evaluate($record, $transition, $evalSignal)) {
                $this->releaseClaim($record);

                return false;
            }

            $journey = $this->entityManager->getEntityById(Journey::ENTITY_TYPE, (string) $record->get('journeyId'));

            if (!$journey || $journey->get('status') !== Journey::STATUS_ACTIVE) {
                $this->releaseClaim($record);

                return false;
            }

            $target = $this->entityManager->getEntityById(
                (string) $record->get('targetType'),
                (string) $record->get('targetId')
            );

            if (!$target) {
                $this->markFailed($record, 'target_missing');

                return false;
            }

            try {
                $this->tenantGuard->assertRecordMatchesJourney($record, $journey);
                $this->tenantGuard->assertTargetBelongsToJourney($target, $journey);
            } catch (Throwable $e) {
                $this->log->warning('TransitionExecutor: tenant guard: ' . $e->getMessage());
                $this->markFailed($record, 'tenant_mismatch');

                return false;
            }

            $fromStage = $currentStageId
                ? $this->entityManager->getEntityById(JourneyStage::ENTITY_TYPE, (string) $currentStageId)
                : null;

            $toStage = $this->entityManager->getEntityById(
                JourneyStage::ENTITY_TYPE,
                (string) $transition->get('toStageId')
            );

            if (!$toStage) {
                $this->markFailed($record, 'to_stage_missing');

                return false;
            }

            try {
                $this->tenantGuard->assertStageInJourney($toStage, $journey);
                if ($fromStage) {
                    $this->tenantGuard->assertStageInJourney($fromStage, $journey);
                }
            } catch (Throwable $e) {
                $this->markFailed($record, 'stage_tenant_mismatch');

                return false;
            }

            if ($fromStage) {
                $exitResult = $this->actionRunner->runForStage(
                    $fromStage,
                    JourneyStageAction::TRIGGER_ON_EXIT,
                    $target,
                    $record,
                    $journey,
                );

                if (!$exitResult['ok']) {
                    return $this->handleActionFailure($record, $exitResult['error'] ?? 'on_exit_failed');
                }
            }

            $now = date('Y-m-d H:i:s');
            $record->set([
                'currentStageId' => $toStage->getId(),
                'enteredStageAt' => $now,
                'status' => JourneyRecord::STATUS_ACTIVE,
                'claimedAt' => null,
                'retryCount' => 0,
            ]);
            $this->entityManager->saveEntity($record, [
                SaveOption::SILENT => true,
                self::SKIP_OPT => true,
            ]);

            $this->writeLog($record, $journey, $fromStage, $toStage, $transition, $firedBy, $signal);

            $enterResult = $this->actionRunner->runForStage(
                $toStage,
                JourneyStageAction::TRIGGER_ON_ENTER,
                $target,
                $record,
                $journey,
            );

            if (!$enterResult['ok']) {
                return $this->handleActionFailure($record, $enterResult['error'] ?? 'on_enter_failed');
            }

            $tenantId = (string) ($record->get('tenantId') ?: $journey->get('tenantId') ?: '');

            if ($tenantId !== '') {
                $this->lifecycleEmitter->emit($tenantId, JourneyLifecycleEmitter::CODE_STAGE_ENTERED, [
                    'contactId' => $record->get('targetType') === 'Contact' ? $record->get('targetId') : null,
                    'parentType' => $record->get('targetType'),
                    'parentId' => $record->get('targetId'),
                    'properties' => [
                        'journeyId' => $journey->getId(),
                        'recordId' => $record->getId(),
                        'fromStageId' => $fromStage?->getId(),
                        'toStageId' => $toStage->getId(),
                        'transitionId' => $transition->getId(),
                    ],
                    'detail' => $journey->get('name'),
                ]);
            }

            $this->handleTerminalStage($record, $journey, $toStage, $tenantId, $target);
            $this->checkGoal($record, $journey, $target, $tenantId, $signal);

            return true;
        } catch (Throwable $e) {
            $this->log->error('TransitionExecutor: ' . $e->getMessage());
            $this->handleActionFailure($record, $e->getMessage());

            return false;
        }
    }

    private function claim(Entity $record): bool
    {
        if ($record->get('status') !== JourneyRecord::STATUS_ACTIVE) {
            return false;
        }

        $now = date('Y-m-d H:i:s');

        $updateQuery = $this->entityManager
            ->getQueryBuilder()
            ->update()
            ->in(JourneyRecord::ENTITY_TYPE)
            ->set([
                'status' => JourneyRecord::STATUS_PROCESSING,
                'claimedAt' => $now,
            ])
            ->where([
                'id' => $record->getId(),
                'status' => JourneyRecord::STATUS_ACTIVE,
            ])
            ->build();

        $sth = $this->entityManager->getQueryExecutor()->execute($updateQuery);

        if ($sth->rowCount() === 0) {
            return false;
        }

        $record->set([
            'status' => JourneyRecord::STATUS_PROCESSING,
            'claimedAt' => $now,
        ]);

        return true;
    }

    private function releaseClaim(Entity $record): void
    {
        $record->set([
            'status' => JourneyRecord::STATUS_ACTIVE,
            'claimedAt' => null,
        ]);
        $this->entityManager->saveEntity($record, [
            SaveOption::SILENT => true,
            self::SKIP_OPT => true,
        ]);
    }

    private function handleActionFailure(Entity $record, string $error): bool
    {
        $retry = (int) $record->get('retryCount') + 1;

        if ($retry >= self::MAX_RETRIES) {
            $this->markFailed($record, substr($error, 0, 100));

            return false;
        }

        $record->set([
            'status' => JourneyRecord::STATUS_ACTIVE,
            'claimedAt' => null,
            'retryCount' => $retry,
            'exitReason' => substr($error, 0, 100),
        ]);
        $this->entityManager->saveEntity($record, [
            SaveOption::SILENT => true,
            self::SKIP_OPT => true,
        ]);

        return false;
    }

    private function markFailed(Entity $record, string $reason): void
    {
        $record->set([
            'status' => JourneyRecord::STATUS_FAILED,
            'claimedAt' => null,
            'exitReason' => substr($reason, 0, 100),
        ]);
        $this->entityManager->saveEntity($record, [
            SaveOption::SILENT => true,
            self::SKIP_OPT => true,
        ]);

        $journeyId = $record->get('journeyId');
        if ($journeyId) {
            $this->incrementCounter((string) $journeyId, 'activeCount', -1);
        }
    }

    /**
     * @param array{code?: string, payload?: array<string, mixed>, eventId?: string}|null $signal
     */
    private function writeLog(
        Entity $record,
        Entity $journey,
        ?Entity $fromStage,
        Entity $toStage,
        Entity $transition,
        string $firedBy,
        ?array $signal,
    ): void {
        $log = $this->entityManager->getNewEntity(JourneyRecordLog::ENTITY_TYPE);
        $log->set([
            'recordId' => $record->getId(),
            'journeyId' => $journey->getId(),
            'fromStageId' => $fromStage?->getId(),
            'toStageId' => $toStage->getId(),
            'transitionId' => $transition->getId(),
            'firedBy' => $firedBy,
            'eventPayload' => $signal,
            'tenantId' => $record->get('tenantId') ?: $journey->get('tenantId'),
            'teamsIds' => $journey->get('teamsIds') ?: [],
        ]);

        $this->entityManager->saveEntity($log, [
            SaveOption::SILENT => true,
            self::SKIP_OPT => true,
        ]);
    }

    private function handleTerminalStage(
        Entity $record,
        Entity $journey,
        Entity $toStage,
        string $tenantId,
        Entity $target,
    ): void {
        $stageType = $toStage->get('stageType');

        if ($stageType === JourneyStage::TYPE_SUCCESS) {
            $record->set([
                'status' => JourneyRecord::STATUS_COMPLETED,
                'exitReason' => 'success-stage',
            ]);
            $this->entityManager->saveEntity($record, [
                SaveOption::SILENT => true,
                self::SKIP_OPT => true,
            ]);
            $this->incrementCounter((string) $journey->getId(), 'activeCount', -1);
            $this->incrementCounter((string) $journey->getId(), 'completedCount', 1);

            if ($tenantId !== '') {
                $this->lifecycleEmitter->emit($tenantId, JourneyLifecycleEmitter::CODE_COMPLETED, [
                    'contactId' => $record->get('targetType') === 'Contact' ? $record->get('targetId') : null,
                    'parentType' => $record->get('targetType'),
                    'parentId' => $record->get('targetId'),
                    'properties' => [
                        'journeyId' => $journey->getId(),
                        'recordId' => $record->getId(),
                        'stageId' => $toStage->getId(),
                    ],
                    'detail' => $journey->get('name'),
                ]);
            }

            return;
        }

        if ($stageType === JourneyStage::TYPE_EXIT) {
            $record->set([
                'status' => JourneyRecord::STATUS_EXITED,
                'exitReason' => 'exit-stage',
            ]);
            $this->entityManager->saveEntity($record, [
                SaveOption::SILENT => true,
                self::SKIP_OPT => true,
            ]);
            $this->incrementCounter((string) $journey->getId(), 'activeCount', -1);
            $this->incrementCounter((string) $journey->getId(), 'exitedCount', 1);
        }
    }

    /**
     * Public goal check entry for signal goal-fast-path jobs.
     *
     * @param array{code?: string, payload?: array<string, mixed>}|null $signal
     */
    public function checkGoalForRecord(string $recordId, ?array $signal = null): void
    {
        $record = $this->entityManager->getEntityById(JourneyRecord::ENTITY_TYPE, $recordId);

        if (!$record || $record->get('status') !== JourneyRecord::STATUS_ACTIVE) {
            return;
        }

        $journey = $this->entityManager->getEntityById(
            Journey::ENTITY_TYPE,
            (string) $record->get('journeyId')
        );

        if (!$journey) {
            return;
        }

        $target = $this->entityManager->getEntityById(
            (string) $record->get('targetType'),
            (string) $record->get('targetId')
        );

        if (!$target) {
            return;
        }

        $tenantId = (string) ($record->get('tenantId') ?: $journey->get('tenantId') ?: '');
        $this->checkGoal($record, $journey, $target, $tenantId, $signal);
    }

    /**
     * @param array{code?: string, payload?: array<string, mixed>}|null $signal
     */
    private function checkGoal(
        Entity $record,
        Entity $journey,
        Entity $target,
        string $tenantId,
        ?array $signal,
    ): void {
        if (!in_array($record->get('status'), [JourneyRecord::STATUS_ACTIVE, JourneyRecord::STATUS_COMPLETED], true)) {
            // already exited/failed
        }

        if ($record->get('status') !== JourneyRecord::STATUS_ACTIVE) {
            return;
        }

        $codes = $journey->get('goalEventCodes') ?: [];
        if (!is_array($codes)) {
            $codes = [];
        }

        $matched = false;

        if ($signal && isset($signal['code']) && in_array($signal['code'], $codes, true)) {
            $matched = true;
        }

        if (!$matched) {
            $filter = $journey->get('goalEntityFilter');
            if ($filter && $filter !== [] && $filter !== new \stdClass()) {
                $fakeTransition = $this->entityManager->getNewEntity('JourneyTransition');
                $fakeTransition->set('conditionsGroup', [
                    'type' => 'entityFilter',
                    'where' => $filter instanceof \stdClass
                        ? json_decode(json_encode($filter) ?: '[]', true)
                        : $filter,
                ]);

                if ($this->evaluator->evaluate($record, $fakeTransition, $signal)) {
                    $matched = true;
                }
            }
        }

        if (!$matched) {
            return;
        }

        $record->set([
            'status' => JourneyRecord::STATUS_COMPLETED,
            'exitReason' => 'goal',
        ]);
        $this->entityManager->saveEntity($record, [
            SaveOption::SILENT => true,
            self::SKIP_OPT => true,
        ]);
        $this->incrementCounter((string) $journey->getId(), 'activeCount', -1);
        $this->incrementCounter((string) $journey->getId(), 'completedCount', 1);
        $this->incrementCounter((string) $journey->getId(), 'goalCount', 1);

        if ($tenantId !== '') {
            $this->lifecycleEmitter->emit($tenantId, JourneyLifecycleEmitter::CODE_GOAL_REACHED, [
                'contactId' => $record->get('targetType') === 'Contact' ? $record->get('targetId') : null,
                'parentType' => $record->get('targetType'),
                'parentId' => $record->get('targetId'),
                'properties' => [
                    'journeyId' => $journey->getId(),
                    'recordId' => $record->getId(),
                ],
                'detail' => $journey->get('name'),
            ]);
        }
    }

    private function incrementCounter(string $journeyId, string $field, int $delta): void
    {
        try {
            $journey = $this->entityManager->getEntityById(Journey::ENTITY_TYPE, $journeyId);
            if (!$journey) {
                return;
            }

            $value = max(0, (int) $journey->get($field) + $delta);
            $journey->set($field, $value);
            $this->entityManager->saveEntity($journey, [
                SaveOption::SILENT => true,
                self::SKIP_OPT => true,
            ]);
        } catch (Throwable $e) {
            $this->log->warning('TransitionExecutor: counter update failed: ' . $e->getMessage());
        }
    }
}

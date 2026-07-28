<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureJourney\Services;

use Espo\Core\Utils\Log;
use Espo\Entities\User;
use Espo\Modules\FeatureJourney\Entities\Journey;
use Espo\Modules\FeatureJourney\Entities\JourneyRecord;
use Espo\Modules\FeatureJourney\Entities\JourneyRecordLog;
use Espo\Modules\FeatureJourney\Entities\JourneyStage;
use Espo\Modules\FeatureJourney\Entities\JourneyStageAction;
use Espo\Modules\FeatureJourney\Entities\JourneyTransition;
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
        private JourneyRunIdentity $identity,
        private Log $log,
    ) {}

    /**
     * @param array{code?: string, payload?: array<string, mixed>, eventId?: string}|null $signal
     */
    public function execute(
        string $recordId,
        string $transitionId,
        ?array $signal = null,
        string $firedBy = 'system',
    ): bool {
        return $this->executeInternal($recordId, $transitionId, $signal, $firedBy);
    }

    /**
     * Execute a candidate whose conditions were already checked by matches().
     *
     * @param array{code?: string, payload?: array<string, mixed>, eventId?: string}|null $signal
     */
    public function executeMatched(
        string $recordId,
        string $transitionId,
        ?array $signal = null,
        string $firedBy = 'system',
    ): bool {
        return $this->executeInternal($recordId, $transitionId, $signal, $firedBy, false, true);
    }

    /**
     * Execute an enrollment edge only after its dispatcher created the record.
     *
     * @param array{code?: string, payload?: array<string, mixed>, eventId?: string}|null $signal
     */
    public function executeEnrollment(
        string $recordId,
        string $transitionId,
        ?array $signal = null,
        string $firedBy = 'system',
    ): bool {
        return $this->executeInternal($recordId, $transitionId, $signal, $firedBy, true);
    }

    /**
     * Complete a matched goal through its configured Success stage.
     *
     * @param array{code?: string, payload?: array<string, mixed>, eventId?: string}|null $signal
     */
    public function executeGoal(
        string $recordId,
        ?array $signal = null,
        string $firedBy = 'system',
    ): bool {
        $record = $this->entityManager->getEntityById(JourneyRecord::ENTITY_TYPE, $recordId);

        if (!$record || $record->get('status') !== JourneyRecord::STATUS_ACTIVE) {
            return false;
        }

        if (!$this->claim($record)) {
            return false;
        }

        $record = $this->entityManager->getEntityById(JourneyRecord::ENTITY_TYPE, $recordId);
        if (!$record) {
            return false;
        }

        try {
            $journey = $this->entityManager->getEntityById(
                Journey::ENTITY_TYPE,
                (string) $record->get('journeyId'),
            );

            if (!$journey || $journey->get('status') !== Journey::STATUS_ACTIVE) {
                $this->releaseClaim($record);

                return false;
            }

            $target = $this->entityManager->getEntityById(
                (string) $record->get('targetType'),
                (string) $record->get('targetId'),
            );

            if (!$target) {
                $this->markFailed($record, 'target_missing');

                return false;
            }

            try {
                $this->tenantGuard->assertRecordMatchesJourney($record, $journey);
                $this->tenantGuard->assertTargetBelongsToJourney($target, $journey);
            } catch (Throwable $e) {
                $this->log->warning('TransitionExecutor: goal tenant guard: ' . $e->getMessage());
                $this->markFailed($record, 'tenant_mismatch');

                return false;
            }

            $actor = $this->identity->fromRecord($record, $journey);
            if (!$this->goalMatches($record, $journey, $target, $signal, $actor)) {
                $this->releaseClaim($record);

                return false;
            }

            $goalSuccessStageId = trim((string) ($journey->get('goalSuccessStageId') ?? ''));
            $toStage = $this->resolveGoalSuccessStage($journey);

            if (!$toStage && $goalSuccessStageId === '') {
                return $this->completeLegacyGoal($record, $journey, $signal);
            }

            if (
                !$toStage ||
                !$toStage->get('isActive') ||
                $toStage->get('stageType') !== JourneyStage::TYPE_SUCCESS ||
                (string) $toStage->get('journeyId') !== (string) $journey->getId()
            ) {
                $this->log->error(
                    "TransitionExecutor: invalid goal Success stage for journey {$journey->getId()}"
                );
                $this->releaseClaim($record);

                return false;
            }

            $currentStageId = $record->get('currentStageId');
            $fromStage = $currentStageId
                ? $this->entityManager->getEntityById(JourneyStage::ENTITY_TYPE, (string) $currentStageId)
                : null;

            return $this->moveClaimedRecord(
                $record,
                $journey,
                $target,
                $fromStage,
                $toStage,
                null,
                $signal,
                $firedBy,
                $actor,
                true,
            );
        } catch (Throwable $e) {
            $this->log->error('TransitionExecutor goal: ' . $e->getMessage());
            $this->handleActionFailure($record, $e->getMessage());

            return false;
        }
    }

    /**
     * @param array{code?: string, payload?: array<string, mixed>, eventId?: string}|null $signal
     */
    private function executeInternal(
        string $recordId,
        string $transitionId,
        ?array $signal = null,
        string $firedBy = 'system',
        bool $allowEnrollment = false,
        bool $conditionsAlreadyMatched = false,
    ): bool {
        $record = $this->entityManager->getEntityById(JourneyRecord::ENTITY_TYPE, $recordId);

        if (!$record) {
            $this->log->warning("TransitionExecutor: record {$recordId} not found");

            return false;
        }

        $transition = $this->entityManager->getEntityById(JourneyTransition::ENTITY_TYPE, $transitionId);

        if (!$transition || !$transition->get('isActive')) {
            return false;
        }

        if ($record->get('status') !== JourneyRecord::STATUS_ACTIVE) {
            return false;
        }

        if (!$this->transitionAppliesToRecord($record, $transition, $allowEnrollment)) {
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

        if (!$this->transitionAppliesToRecord($record, $transition, $allowEnrollment)) {
            $this->releaseClaim($record);

            return false;
        }

        $currentStageId = $record->get('currentStageId');

        try {
            $evalSignal = $signal;
            if ($evalSignal === null) {
                $evalSignal = ['firedBy' => $firedBy];
            } else {
                $evalSignal['firedBy'] = $firedBy;
            }

            $journey = $this->entityManager->getEntityById(Journey::ENTITY_TYPE, (string) $record->get('journeyId'));

            if (!$journey || $journey->get('status') !== Journey::STATUS_ACTIVE) {
                $this->releaseClaim($record);

                return false;
            }

            $actor = $this->identity->fromRecord($record, $journey);

            if (
                !$conditionsAlreadyMatched &&
                !$this->evaluator->evaluate($record, $transition, $evalSignal, $actor)
            ) {
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

            return $this->moveClaimedRecord(
                $record,
                $journey,
                $target,
                $fromStage,
                $toStage,
                $transition,
                $signal,
                $firedBy,
                $actor,
            );
        } catch (Throwable $e) {
            $this->log->error('TransitionExecutor: ' . $e->getMessage());
            $this->handleActionFailure($record, $e->getMessage());

            return false;
        }
    }

    /**
     * @param array{code?: string, payload?: array<string, mixed>, eventId?: string}|null $signal
     */
    private function moveClaimedRecord(
        Entity $record,
        Entity $journey,
        Entity $target,
        ?Entity $fromStage,
        Entity $toStage,
        ?Entity $transition,
        ?array $signal,
        string $firedBy,
        ?User $actor,
        bool $goalCompletion = false,
    ): bool {
        try {
            $this->tenantGuard->assertStageInJourney($toStage, $journey);
            if ($fromStage) {
                $this->tenantGuard->assertStageInJourney($fromStage, $journey);
            }
        } catch (Throwable $e) {
            $this->markFailed($record, 'stage_tenant_mismatch');

            return false;
        }

        if ($fromStage && $fromStage->getId() !== $toStage->getId()) {
            $exitResult = $this->actionRunner->runForStage(
                $fromStage,
                JourneyStageAction::TRIGGER_ON_EXIT,
                $target,
                $record,
                $journey,
                $actor,
            );

            if (!$exitResult['ok']) {
                return $this->handleActionFailure($record, $exitResult['error'] ?? 'on_exit_failed');
            }
        }

        $record->set([
            'currentStageId' => $toStage->getId(),
            'enteredStageAt' => date('Y-m-d H:i:s'),
            'status' => JourneyRecord::STATUS_ACTIVE,
            'claimedAt' => $record->get('claimedAt'),
            'retryCount' => 0,
        ]);
        $this->entityManager->saveEntity($record, [
            SaveOption::SILENT => true,
            self::SKIP_OPT => true,
        ]);

        $logSignal = $goalCompletion
            ? array_merge($signal ?? [], ['goal' => true])
            : $signal;
        $this->writeLog($record, $journey, $fromStage, $toStage, $transition, $firedBy, $logSignal);

        $enterResult = $this->actionRunner->runForStage(
            $toStage,
            JourneyStageAction::TRIGGER_ON_ENTER,
            $target,
            $record,
            $journey,
            $actor,
        );

        if (!$enterResult['ok']) {
            return $this->handleActionFailure($record, $enterResult['error'] ?? 'on_enter_failed');
        }

        $tenantId = (string) ($record->get('tenantId') ?: $journey->get('tenantId') ?: '');

        if ($tenantId !== '') {
            $properties = [
                'journeyId' => $journey->getId(),
                'recordId' => $record->getId(),
                'fromStageId' => $fromStage?->getId(),
                'toStageId' => $toStage->getId(),
                'transitionId' => $transition?->getId(),
            ];
            if ($goalCompletion) {
                $properties['goal'] = true;
            }

            $this->lifecycleEmitter->emit($tenantId, JourneyLifecycleEmitter::CODE_STAGE_ENTERED, [
                'contactId' => $record->get('targetType') === 'Contact' ? $record->get('targetId') : null,
                'parentType' => $record->get('targetType'),
                'parentId' => $record->get('targetId'),
                'properties' => $properties,
                'detail' => $journey->get('name'),
            ]);
        }

        if ($goalCompletion) {
            $this->handleTerminalStage($record, $journey, $toStage, $tenantId, true, $signal);

            return true;
        }

        if ($this->goalMatches($record, $journey, $target, $signal, $actor)) {
            return $this->completeMatchedGoalFromClaim(
                $record,
                $journey,
                $target,
                $toStage,
                $signal,
                $firedBy,
                $actor,
            );
        }

        $this->handleTerminalStage($record, $journey, $toStage, $tenantId, false, $signal);

        if ($record->get('status') === JourneyRecord::STATUS_ACTIVE && $record->get('claimedAt')) {
            $this->releaseClaim($record);
        }

        return true;
    }

    /**
     * @param array{code?: string, payload?: array<string, mixed>, eventId?: string}|null $signal
     */
    private function completeMatchedGoalFromClaim(
        Entity $record,
        Entity $journey,
        Entity $target,
        Entity $fromStage,
        ?array $signal,
        string $firedBy,
        ?User $actor,
    ): bool {
        $goalSuccessStageId = trim((string) ($journey->get('goalSuccessStageId') ?? ''));
        $goalStage = $this->resolveGoalSuccessStage($journey);

        if (!$goalStage && $goalSuccessStageId === '') {
            return $this->completeLegacyGoal($record, $journey, $signal);
        }

        if (
            !$goalStage ||
            !$goalStage->get('isActive') ||
            $goalStage->get('stageType') !== JourneyStage::TYPE_SUCCESS ||
            (string) $goalStage->get('journeyId') !== (string) $journey->getId()
        ) {
            $this->log->error(
                "TransitionExecutor: invalid goal Success stage for journey {$journey->getId()}"
            );
            $this->releaseClaim($record);

            return false;
        }

        if ($goalStage->getId() === $fromStage->getId()) {
            $tenantId = (string) ($record->get('tenantId') ?: $journey->get('tenantId') ?: '');
            $this->handleTerminalStage($record, $journey, $goalStage, $tenantId, true, $signal);

            return true;
        }

        return $this->moveClaimedRecord(
            $record,
            $journey,
            $target,
            $fromStage,
            $goalStage,
            null,
            $signal,
            $firedBy,
            $actor,
            true,
        );
    }

    private function resolveGoalSuccessStage(Entity $journey): ?Entity
    {
        $goalStageId = (string) ($journey->get('goalSuccessStageId') ?? '');
        if ($goalStageId !== '') {
            return $this->entityManager->getEntityById(JourneyStage::ENTITY_TYPE, $goalStageId);
        }

        // Existing active journeys predate the explicit destination field. A single
        // active Success stage is unambiguous; authoring still requires persisting it.
        $stages = $this->entityManager
            ->getRDBRepository(JourneyStage::ENTITY_TYPE)
            ->where([
                'journeyId' => $journey->getId(),
                'stageType' => JourneyStage::TYPE_SUCCESS,
                'isActive' => true,
            ])
            ->order('order', 'ASC')
            ->limit(0, 2)
            ->find();

        $list = [];
        foreach ($stages as $stage) {
            $list[] = $stage;
        }

        if (count($list) !== 1) {
            return null;
        }

        $this->log->warning(
            "TransitionExecutor: journey {$journey->getId()} uses legacy implicit goal Success stage"
        );

        return $list[0];
    }

    /**
     * Preserve pre-goalSuccessStage behavior for already-active legacy journeys
     * that have no unambiguous Success destination.
     *
     * @param array{code?: string, payload?: array<string, mixed>, eventId?: string}|null $signal
     */
    private function completeLegacyGoal(Entity $record, Entity $journey, ?array $signal): bool
    {
        $this->log->warning(
            "TransitionExecutor: journey {$journey->getId()} uses legacy direct goal completion"
        );

        $record->set([
            'status' => JourneyRecord::STATUS_COMPLETED,
            'claimedAt' => null,
            'exitReason' => 'goal',
        ]);
        $this->entityManager->saveEntity($record, [
            SaveOption::SILENT => true,
            self::SKIP_OPT => true,
        ]);
        $this->incrementCounter((string) $journey->getId(), 'activeCount', -1);
        $this->incrementCounter((string) $journey->getId(), 'completedCount', 1);
        $this->incrementCounter((string) $journey->getId(), 'goalCount', 1);

        $tenantId = (string) ($record->get('tenantId') ?: $journey->get('tenantId') ?: '');
        if ($tenantId !== '') {
            $this->emitGoalReached($record, $journey, null, $tenantId, $signal);
        }

        return true;
    }

    /**
     * Preflight used when one wake has multiple ordered candidates. Execution still re-checks
     * everything after claiming the record.
     *
     * @param array{code?: string, payload?: array<string, mixed>, eventId?: string}|null $signal
     */
    public function matches(string $recordId, string $transitionId, ?array $signal = null, string $firedBy = 'system'): bool
    {
        $record = $this->entityManager->getEntityById(JourneyRecord::ENTITY_TYPE, $recordId);
        $transition = $this->entityManager->getEntityById(JourneyTransition::ENTITY_TYPE, $transitionId);

        if (
            !$record ||
            !$transition ||
            !$transition->get('isActive') ||
            $record->get('status') !== JourneyRecord::STATUS_ACTIVE ||
            !$this->transitionAppliesToRecord($record, $transition)
        ) {
            return false;
        }

        $journey = $this->entityManager->getEntityById(
            Journey::ENTITY_TYPE,
            (string) $record->get('journeyId'),
        );

        if (!$journey || $journey->get('status') !== Journey::STATUS_ACTIVE) {
            return false;
        }

        $evalSignal = $signal ?? [];
        $evalSignal['firedBy'] = $firedBy;
        $actor = $this->identity->fromRecord($record, $journey);

        return $this->evaluator->evaluate($record, $transition, $evalSignal, $actor);
    }

    private function transitionAppliesToRecord(
        Entity $record,
        Entity $transition,
        bool $allowEnrollment = false,
    ): bool
    {
        if ((string) $transition->get('journeyId') !== (string) $record->get('journeyId')) {
            return false;
        }

        $scope = JourneyTransition::resolveScope($transition);
        $currentStageId = $record->get('currentStageId');

        if ($scope === JourneyTransition::SCOPE_STAGE) {
            return JourneyTransition::appliesToStage(
                $transition,
                is_string($currentStageId) ? $currentStageId : null,
            );
        }

        if ($scope === JourneyTransition::SCOPE_JOURNEY) {
            if (!$currentStageId) {
                return false;
            }

            return (string) $transition->get('toStageId') !== (string) $currentStageId;
        }

        return $allowEnrollment && !$transition->get('fromStageId');
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
                'claimedAt' => null,
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
        ?Entity $transition,
        string $firedBy,
        ?array $signal,
    ): void {
        $log = $this->entityManager->getNewEntity(JourneyRecordLog::ENTITY_TYPE);
        $log->set([
            'recordId' => $record->getId(),
            'journeyId' => $journey->getId(),
            'fromStageId' => $fromStage?->getId(),
            'toStageId' => $toStage->getId(),
            'transitionId' => $transition?->getId(),
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
        bool $goalMatched,
        ?array $signal,
    ): void {
        $stageType = $toStage->get('stageType');

        if ($stageType === JourneyStage::TYPE_SUCCESS) {
            $record->set([
                'status' => JourneyRecord::STATUS_COMPLETED,
                'claimedAt' => null,
                'exitReason' => $goalMatched ? 'goal' : 'success-stage',
            ]);
            $this->entityManager->saveEntity($record, [
                SaveOption::SILENT => true,
                self::SKIP_OPT => true,
            ]);
            $this->incrementCounter((string) $journey->getId(), 'activeCount', -1);
            $this->incrementCounter((string) $journey->getId(), 'completedCount', 1);
            if ($goalMatched) {
                $this->incrementCounter((string) $journey->getId(), 'goalCount', 1);
            }

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

                if ($goalMatched) {
                    $this->emitGoalReached($record, $journey, $toStage, $tenantId, $signal);
                }
            }

            return;
        }

        if ($stageType === JourneyStage::TYPE_EXIT) {
            $record->set([
                'status' => JourneyRecord::STATUS_EXITED,
                'claimedAt' => null,
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
     * @param array{code?: string, payload?: array<string, mixed>, eventId?: string}|null $signal
     */
    private function emitGoalReached(
        Entity $record,
        Entity $journey,
        ?Entity $stage,
        string $tenantId,
        ?array $signal,
    ): void {
        $goalCodes = $journey->get('goalEventCodes');
        $goalCode = is_array($goalCodes) &&
            isset($signal['code']) &&
            in_array($signal['code'], $goalCodes, true)
                ? $signal['code']
                : null;

        $this->lifecycleEmitter->emit($tenantId, JourneyLifecycleEmitter::CODE_GOAL_REACHED, [
            'contactId' => $record->get('targetType') === 'Contact' ? $record->get('targetId') : null,
            'parentType' => $record->get('targetType'),
            'parentId' => $record->get('targetId'),
            'properties' => [
                'journeyId' => $journey->getId(),
                'recordId' => $record->getId(),
                'stageId' => $stage?->getId(),
                'goalCode' => $goalCode,
            ],
            'detail' => $journey->get('name'),
        ]);
    }

    /**
     * @param array{code?: string, payload?: array<string, mixed>}|null $signal
     */
    private function goalMatches(
        Entity $record,
        Entity $journey,
        Entity $target,
        ?array $signal,
        ?User $actor = null,
    ): bool {
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
            $hasFilter = $filter instanceof \stdClass
                ? (array) $filter !== []
                : is_array($filter) && $filter !== [];

            if ($hasFilter) {
                $fakeTransition = $this->entityManager->getNewEntity('JourneyTransition');
                $fakeTransition->set('conditionsGroup', [
                    'type' => 'entityFilter',
                    'where' => $filter instanceof \stdClass
                        ? json_decode(json_encode($filter) ?: '[]', true)
                        : $filter,
                ]);

                if ($this->evaluator->evaluate($record, $fakeTransition, $signal, $actor)) {
                    $matched = true;
                }
            }
        }

        if (!$matched) {
            return false;
        }

        return true;
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

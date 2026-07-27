<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureAutomation\Services;

use Espo\Core\AclManager;
use Espo\Core\Exceptions\Error;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Job\JobSchedulerFactory;
use Espo\Core\Job\QueueName;
use Espo\Core\ORM\Repository\Option\SaveOption;
use Espo\Core\Utils\Log;
use Espo\Entities\User;
use Espo\Modules\FeatureAutomation\Entities\Automation;
use Espo\Modules\FeatureAutomation\Entities\AutomationRun;
use Espo\Modules\FeatureAutomation\Entities\AutomationRunItem;
use Espo\Modules\FeatureAutomation\Jobs\ProcessAutomationRunChunk;
use Espo\Modules\FeatureJourney\Services\TenantGuard;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Throwable;

class AutomationRunner
{
    private const CHUNK_SIZE = 25;
    private const MAX_CHILD_DEPTH = 3;

    public function __construct(
        private EntityManager $entityManager,
        private AutomationDefinitionValidator $validator,
        private MapMaterializer $mapMaterializer,
        private AutomationActionRunner $actionRunner,
        private MachineProcessor $machineProcessor,
        private ScheduleHelper $scheduleHelper,
        private JoinCoordinator $joinCoordinator,
        private TenantGuard $tenantGuard,
        private AutomationTriggerFilterEvaluator $triggerFilterEvaluator,
        private AutomationRunIdentity $identity,
        private AclManager $aclManager,
        private JobSchedulerFactory $jobSchedulerFactory,
        private RunDataBag $runDataBag,
        private CrossTenantAccess $crossTenantAccess,
        private Log $log,
    ) {}

    public function activate(string $id): Entity
    {
        $automation = $this->getAutomation($id);
        $this->validator->normalizeAndValidate($automation);

        if ($automation->get('status') === Automation::STATUS_ARCHIVED) {
            throw new Error('Cannot activate archived automation.');
        }

        // Fail fast: non-manual triggers need an ACL identity before go-live.
        $triggerType = (string) ($automation->get('triggerType') ?? '');
        if ($triggerType !== '' && $triggerType !== Automation::TRIGGER_MANUAL) {
            $this->identity->resolve($automation, $triggerType);
        }

        $scheduling = trim((string) ($automation->get('scheduling') ?? ''));
        $timezone = (string) ($automation->get('timezone') ?: 'UTC');
        $activatedAt = date('Y-m-d H:i:s');
        $scheduleStartAt = new \DateTimeImmutable($activatedAt, new \DateTimeZone('UTC'));
        $next = null;
        if ($automation->get('triggerType') === Automation::TRIGGER_SCHEDULE) {
            if ($scheduling === '') {
                throw new Error('RRULE is required for a scheduled automation.');
            }

            try {
                $this->scheduleHelper->validate($scheduling, $timezone);
            } catch (\InvalidArgumentException $e) {
                throw new Error($e->getMessage());
            }

            $next = $this->scheduleHelper->nextRunAt(
                $scheduling,
                $timezone,
                null,
                $scheduleStartAt,
            );
        }

        $automation->set([
            'status' => Automation::STATUS_ACTIVE,
            'activatedAt' => $activatedAt,
            'nextRunAt' => $next,
        ]);
        $this->entityManager->saveEntity($automation);

        return $automation;
    }

    public function pause(string $id): Entity
    {
        $automation = $this->getAutomation($id);
        $automation->set('status', Automation::STATUS_PAUSED);
        $this->entityManager->saveEntity($automation);

        return $automation;
    }

    public function archive(string $id): Entity
    {
        $automation = $this->getAutomation($id);
        $automation->set('status', Automation::STATUS_ARCHIVED);
        $this->entityManager->saveEntity($automation);

        return $automation;
    }

    /**
     * @param array<string, mixed> $triggerPayload
     */
    public function runNow(
        string $id,
        string $triggeredBy = 'manual',
        array $triggerPayload = [],
        ?string $overrideUserId = null,
    ): Entity {
        $automation = $this->getAutomation($id);

        if (!in_array($automation->get('status'), [
            Automation::STATUS_ACTIVE,
            Automation::STATUS_DRAFT,
            Automation::STATUS_PAUSED,
        ], true)) {
            throw new Error('Automation cannot run in status ' . $automation->get('status'));
        }

        return $this->startRun($automation, $triggeredBy, $triggerPayload, $overrideUserId);
    }

    /**
     * Dry-run: materialize map + plan actions / machine transitions with zero side effects.
     *
     * @param array<string, mixed> $triggerPayload
     * @return array<string, mixed>
     */
    public function simulate(
        string $id,
        array $triggerPayload = [],
        int $maxPreview = 50,
        ?string $overrideUserId = null,
    ): array {
        $automation = $this->getAutomation($id);
        $def = $this->validator->normalizeAndValidate($automation);
        $kind = (string) ($def['kind'] ?? 'batch');
        $automationTenantId = $automation->get('tenantId')
            ? (string) $automation->get('tenantId')
            : null;
        $maxPreview = max(1, min(200, $maxPreview));
        $warnings = [];

        $actor = $this->identity->resolve($automation, 'manual', $overrideUserId);

        if ($kind === 'machine') {
            $rows = $this->materializeMachineItems(
                $automation,
                $def,
                $automationTenantId,
                $triggerPayload,
                $actor,
            );
        } else {
            $first = $this->firstStage($def);
            $mapDef = [
                'map' => $first['map'] ?? $def['map'] ?? [],
                'limits' => $def['limits'] ?? [],
            ];
            if (($first['scope'] ?? 'forEach') === 'once') {
                $rows = [$this->buildOnceRow($automation, $def, null, $triggerPayload, $actor)];
            } else {
                $rows = $this->mapMaterializer->materialize(
                    $mapDef,
                    $automationTenantId,
                    $triggerPayload,
                    $actor,
                    $this->crossTenantAccess->isAuthorized($automation, $actor),
                );
            }
        }

        $itemCount = count($rows);
        if ($itemCount > $maxPreview) {
            $warnings[] = "Preview truncated to {$maxPreview} of {$itemCount} items.";
            $rows = array_slice($rows, 0, $maxPreview);
        }

        $preview = [];
        foreach ($rows as $row) {
            $targetType = (string) ($row['targetType'] ?? '');
            $targetId = (string) ($row['targetId'] ?? '');
            $tenantId = (string) ($row['tenantId'] ?? $automationTenantId ?? '');
            $payload = is_array($row['payload'] ?? null) ? $row['payload'] : [];

            $entry = [
                'targetType' => $targetType,
                'targetId' => $targetId,
                'tenantId' => $tenantId !== '' ? $tenantId : null,
                'payload' => $payload,
                'actions' => [],
            ];

            if ($targetType === '' || $targetId === '' || $tenantId === '') {
                $entry['actions'] = [['status' => 'error', 'error' => 'missing tenant/target']];
                $preview[] = $entry;
                continue;
            }

            $target = $this->entityManager->getEntityById($targetType, $targetId);
            if (!$target) {
                $entry['actions'] = [['status' => 'error', 'error' => 'target not found']];
                $preview[] = $entry;
                continue;
            }

            if ($kind === 'machine') {
                $trace = $this->machineProcessor->trace(
                    $def,
                    $automation,
                    $target,
                    $tenantId,
                    $payload,
                    $actor,
                );
                $entry['actions'] = $trace['log'];
                $entry['machineStatus'] = $trace['status'];
                $entry['finalState'] = $trace['payload']['_state'] ?? null;
            } else {
                $stage = $this->firstStage($def) ?? [];
                $plan = $this->actionRunner->planActions(
                    $stage['actions'] ?? $def['actions'] ?? [],
                    $automation,
                    $target,
                    $tenantId,
                    $payload,
                    (string) ($stage['itemMode'] ?? $def['itemMode'] ?? 'allMatching'),
                    $actor,
                );
                $entry['actions'] = $plan['log'];
                $entry['stageId'] = $stage['id'] ?? 's0';
                $entry['scope'] = $stage['scope'] ?? 'forEach';
            }

            $preview[] = $entry;
        }

        $stagesMeta = [];
        if ($kind !== 'machine') {
            foreach ($this->batchStages($def) as $si => $st) {
                $stagesMeta[] = [
                    'index' => $si,
                    'id' => $st['id'] ?? ('s' . $si),
                    'scope' => $st['scope'] ?? 'forEach',
                    'mapSteps' => count($st['map'] ?? []),
                    'actions' => count($st['actions'] ?? []),
                ];
            }
        }

        return [
            'kind' => $kind,
            'itemCount' => $itemCount,
            'truncated' => count($preview),
            'items' => $preview,
            'stages' => $stagesMeta,
            'warnings' => $warnings,
        ];
    }

    public function processDue(): void
    {
        $list = $this->entityManager
            ->getRDBRepository(Automation::ENTITY_TYPE)
            ->where([
                'status' => Automation::STATUS_ACTIVE,
                'triggerType' => Automation::TRIGGER_SCHEDULE,
            ])
            ->find();

        foreach ($list as $automation) {
            $scheduling = (string) ($automation->get('scheduling') ?? '');
            if ($scheduling === '') {
                continue;
            }

            $tz = (string) ($automation->get('timezone') ?: 'UTC');
            $last = $automation->get('lastRunAt') ? (string) $automation->get('lastRunAt') : null;
            $next = $automation->get('nextRunAt') ? (string) $automation->get('nextRunAt') : null;

            if (!$this->scheduleHelper->isDue(
                $scheduling,
                $last,
                $tz,
                null,
                $this->getScheduleStartAt($automation),
                $next,
            )) {
                continue;
            }

            try {
                $this->startRun($automation, 'schedule', []);
            } catch (Throwable $e) {
                $this->log->error(
                    'AutomationRunner processDue ' . $automation->getId() . ': ' . $e->getMessage()
                );
            }
        }

        $this->wakeWaitingItems();
        $this->refreshJoinWaits();

        $runs = $this->entityManager
            ->getRDBRepository(AutomationRun::ENTITY_TYPE)
            ->where(['status' => AutomationRun::STATUS_RUNNING])
            ->limit(0, 50)
            ->find();

        foreach ($runs as $run) {
            $this->dispatchChunks((string) $run->getId());
            $this->finalizeRunIfComplete((string) $run->getId());
        }

        $this->recoverStaleClaims();
    }

    /**
     * Poll Waiting items that track _joinWaiting / join mode for early wake.
     */
    private function refreshJoinWaits(): void
    {
        $waiting = $this->entityManager
            ->getRDBRepository(AutomationRunItem::ENTITY_TYPE)
            ->where(['status' => AutomationRunItem::STATUS_WAITING])
            ->order('modifiedAt')
            ->limit(0, 100)
            ->find();

        foreach ($waiting as $item) {
            $payload = $this->normalizePayload($item->get('payload'));
            if (empty($payload['_joinWaiting']) && empty($payload['_joinMode']) && empty($payload['_pendingJoins'])) {
                continue;
            }

            try {
                $this->joinCoordinator->checkAndWakeParent((string) $item->getId());
            } catch (Throwable $e) {
                $this->log->warning('refreshJoinWaits: ' . $e->getMessage());
            }
        }

        foreach ($this->joinCoordinator->drainWokenRunIds() as $runId) {
            $this->dispatchChunks($runId);
        }
    }

    /**
     * @param array<string, mixed> $triggerPayload
     */
    public function startRun(
        Entity $automation,
        string $triggeredBy,
        array $triggerPayload = [],
        ?string $overrideUserId = null,
    ): Entity {
        $depth = (int) ($triggerPayload['_depth'] ?? 0);
        if ($depth > self::MAX_CHILD_DEPTH) {
            throw new Error('Child automation depth exceeded (' . self::MAX_CHILD_DEPTH . ').');
        }

        $def = $this->validator->normalizeAndValidate($automation);
        $kind = (string) ($def['kind'] ?? 'batch');
        $automationTenantId = $automation->get('tenantId')
            ? (string) $automation->get('tenantId')
            : null;

        $teamsIds = [];
        try {
            $teamsIds = $automation->getLinkMultipleIdList('teams') ?: [];
        } catch (Throwable) {
            $teamsIds = $automation->get('teamsIds') ?: [];
        }
        if (!is_array($teamsIds)) {
            $teamsIds = [];
        }

        $actor = $this->identity->resolve($automation, $triggeredBy, $overrideUserId);

        $parentRunId = !empty($triggerPayload['_parentRunId'])
            ? (string) $triggerPayload['_parentRunId']
            : null;
        $parentRunItemId = !empty($triggerPayload['_parentRunItemId'])
            ? (string) $triggerPayload['_parentRunItemId']
            : null;

        // Enums: map unknown triggeredBy values to api (child stored as field + payload)
        $triggeredByField = $triggeredBy;
        if (!in_array($triggeredByField, [
            'manual', 'schedule', 'entityChange', 'signal', 'api', 'child',
        ], true)) {
            $triggeredByField = 'api';
        }

        $run = $this->entityManager->getNewEntity(AutomationRun::ENTITY_TYPE);
        $run->set([
            'name' => ($automation->get('name') ?: 'Automation') . ' @ ' . date('Y-m-d H:i'),
            'automationId' => $automation->getId(),
            'status' => AutomationRun::STATUS_RUNNING,
            'triggeredBy' => $triggeredByField,
            'triggerPayload' => $triggerPayload,
            'tenantId' => $automationTenantId,
            'teamsIds' => $teamsIds,
            'startedAt' => date('Y-m-d H:i:s'),
            'itemCount' => 0,
            'doneCount' => 0,
            'failedCount' => 0,
            'skippedCount' => 0,
            'stageIndex' => 0,
            'parentRunId' => $parentRunId,
            'parentRunItemId' => $parentRunItemId,
            'runAsUserId' => $actor->getId(),
        ]);
        $this->entityManager->saveEntity($run, [SaveOption::SKIP_ALL => true]);

        try {
            if ($kind === 'machine') {
                $items = $this->materializeMachineItems(
                    $automation,
                    $def,
                    $automationTenantId,
                    $triggerPayload,
                    $actor,
                );
                $count = $this->persistRunItems(
                    $run,
                    $automation,
                    $items,
                    $automationTenantId,
                    $teamsIds,
                    'machine',
                );
                $run->set('itemCount', $count);
                $this->entityManager->saveEntity($run, [SaveOption::SKIP_ALL => true]);
            } else {
                $this->beginBatchStage(
                    $run,
                    $automation,
                    $def,
                    0,
                    $automationTenantId,
                    $teamsIds,
                    $triggerPayload,
                    $actor,
                );
            }
        } catch (Throwable $e) {
            $run->set([
                'status' => AutomationRun::STATUS_FAILED,
                'errorSummary' => $e->getMessage(),
                'completedAt' => date('Y-m-d H:i:s'),
            ]);
            $this->entityManager->saveEntity($run, [SaveOption::SKIP_ALL => true]);
            $this->touchAutomationAfterRun($automation, 'Failed');

            throw $e;
        }

        $this->touchAutomationAfterRun($automation, 'Running');

        // Empty forEach stages advance; may land on once or complete.
        $this->finalizeRunIfComplete((string) $run->getId());

        $run = $this->entityManager->getEntityById(AutomationRun::ENTITY_TYPE, (string) $run->getId())
            ?: $run;

        if ($run->get('status') === AutomationRun::STATUS_RUNNING) {
            $this->dispatchChunks((string) $run->getId());
        }

        return $run;
    }

    /**
     * Start nested batch/machine from an action (depth-limited).
     *
     * @param array<string, mixed> $triggerPayload
     */
    public function startChildRun(
        string $automationId,
        array $triggerPayload = [],
        ?string $inlineDefinitionJson = null,
    ): Entity {
        if ($inlineDefinitionJson !== null && $inlineDefinitionJson !== '') {
            throw new Error('Inline child definition not supported; use automationId.');
        }

        $depth = (int) ($triggerPayload['_depth'] ?? 0) + 1;
        $triggerPayload['_depth'] = $depth;

        return $this->runNow($automationId, 'child', $triggerPayload);
    }

    /**
     * @param list<string>|null $itemIds
     */
    public function processChunk(string $runId, ?array $itemIds = null): void
    {
        $run = $this->entityManager->getEntityById(AutomationRun::ENTITY_TYPE, $runId);
        if (!$run || $run->get('status') === AutomationRun::STATUS_CANCELLED) {
            return;
        }

        $automation = $this->entityManager->getEntityById(
            Automation::ENTITY_TYPE,
            (string) $run->get('automationId')
        );

        if (!$automation) {
            return;
        }

        $def = $this->validator->normalizeAndValidate($automation);
        $isMachine = ($def['kind'] ?? '') === 'machine';
        $onFailure = $def['onFailure'] ?? [];

        $actor = $this->identity->fromRun($run);

        $where = [
            'runId' => $runId,
            'status' => [
                AutomationRunItem::STATUS_PENDING,
                AutomationRunItem::STATUS_RETRY,
            ],
        ];

        if (is_array($itemIds) && $itemIds !== []) {
            $where['id'] = $itemIds;
        }

        $items = $this->entityManager
            ->getRDBRepository(AutomationRunItem::ENTITY_TYPE)
            ->where($where)
            ->order('createdAt')
            ->limit(0, self::CHUNK_SIZE * 2)
            ->find();

        foreach ($items as $item) {
            if (!$this->claimItem($item)) {
                continue;
            }

            if ($isMachine) {
                $this->processMachineItem($item, $automation, $run, $def, $onFailure, $actor);
            } else {
                $stage = $this->stageForItem($def, $item);
                $this->processItem(
                    $item,
                    $automation,
                    $run,
                    $stage['actions'] ?? [],
                    $stage['onFailure'] ?? [],
                    (string) ($stage['itemMode'] ?? 'allMatching'),
                    $actor,
                );
            }
        }

        $this->finalizeRunIfComplete($runId);
    }

    /**
     * @param array<string, mixed> $def
     * @param list<array<string, mixed>> $onFailure
     */
    private function processMachineItem(
        Entity $item,
        Entity $automation,
        Entity $run,
        array $def,
        array $onFailure,
        User $actor,
    ): void {
        $tenantId = $item->get('tenantId')
            ? (string) $item->get('tenantId')
            : ($automation->get('tenantId') ? (string) $automation->get('tenantId') : null);

        $targetType = $item->get('targetType');
        $targetId = $item->get('targetId');

        if (!$tenantId || !$targetType || !$targetId) {
            $this->finishItem($item, AutomationRunItem::STATUS_FAILED, 'missing tenant/target', [], null);
            $this->bumpRunCounter((string) $run->getId(), 'failedCount');

            return;
        }

        $target = $this->entityManager->getEntityById((string) $targetType, (string) $targetId);
        if (!$target) {
            $this->finishItem($item, AutomationRunItem::STATUS_FAILED, 'target not found', [], null);
            $this->bumpRunCounter((string) $run->getId(), 'failedCount');

            return;
        }

        try {
            if ($target->getEntityType() === 'User') {
                $this->tenantGuard->assertUserInTenant($target->getId(), $tenantId, 'item-user');
            } elseif ($target->getEntityType() !== 'Tenant') {
                $this->tenantGuard->assertEntityTenant($target, $tenantId, 'item-target');
            }
        } catch (Throwable $e) {
            $this->finishItem($item, AutomationRunItem::STATUS_FAILED, $e->getMessage(), [], null);
            $this->bumpRunCounter((string) $run->getId(), 'failedCount');

            return;
        }

        $payload = $this->normalizePayload($item->get('payload'));

        $result = $this->machineProcessor->process(
            $def,
            $automation,
            $run,
            $item,
            $target,
            $tenantId,
            $payload,
            $actor,
        );

        $status = $result['status'];
        $log = $result['log'];
        $newPayload = $result['payload'];
        $wakeAt = isset($newPayload['_wakeAt']) ? (string) $newPayload['_wakeAt'] : null;

        if ($status === 'Waiting') {
            $this->finishItem(
                $item,
                AutomationRunItem::STATUS_WAITING,
                null,
                $log,
                $newPayload,
                $wakeAt
            );

            return;
        }

        if ($status === 'Done') {
            $this->finishItem($item, AutomationRunItem::STATUS_DONE, null, $log, $newPayload);
            $this->bumpRunCounter((string) $run->getId(), 'doneCount');

            return;
        }

        if ($onFailure !== []) {
            try {
                $this->actionRunner->runActions(
                    $onFailure,
                    $automation,
                    $run,
                    $item,
                    $target,
                    $tenantId,
                    $newPayload,
                    'allMatching',
                    false,
                    $actor,
                );
            } catch (Throwable $e) {
                $this->log->warning('onFailure: ' . $e->getMessage());
            }
        }

        $this->finishItem(
            $item,
            AutomationRunItem::STATUS_FAILED,
            $result['error'] ?? 'failed',
            $log,
            $newPayload
        );
        $this->bumpRunCounter((string) $run->getId(), 'failedCount');
    }

    /**
     * @param list<array<string, mixed>> $actions
     * @param list<array<string, mixed>> $onFailure
     */
    private function processItem(
        Entity $item,
        Entity $automation,
        Entity $run,
        array $actions,
        array $onFailure,
        string $itemMode,
        User $actor,
    ): void {
        $tenantId = $item->get('tenantId')
            ? (string) $item->get('tenantId')
            : ($automation->get('tenantId') ? (string) $automation->get('tenantId') : null);

        $targetType = $item->get('targetType');
        $targetId = $item->get('targetId');

        if (!$tenantId || !$targetType || !$targetId) {
            $this->finishItem($item, AutomationRunItem::STATUS_FAILED, 'missing tenant/target', [], null);
            $this->bumpRunCounter((string) $run->getId(), 'failedCount');

            return;
        }

        $target = $this->entityManager->getEntityById((string) $targetType, (string) $targetId);
        if (!$target) {
            $this->finishItem($item, AutomationRunItem::STATUS_FAILED, 'target not found', [], null);
            $this->bumpRunCounter((string) $run->getId(), 'failedCount');

            return;
        }

        try {
            if ($target->getEntityType() === 'User') {
                $this->tenantGuard->assertUserInTenant($target->getId(), $tenantId, 'item-user');
            } elseif ($target->getEntityType() !== 'Tenant') {
                $this->tenantGuard->assertEntityTenant($target, $tenantId, 'item-target');
            }
        } catch (Throwable $e) {
            $this->finishItem($item, AutomationRunItem::STATUS_FAILED, $e->getMessage(), [], null);
            $this->bumpRunCounter((string) $run->getId(), 'failedCount');

            return;
        }

        $payload = $this->normalizePayload($item->get('payload'));

        $result = $this->actionRunner->runActions(
            $actions,
            $automation,
            $run,
            $item,
            $target,
            $tenantId,
            $payload,
            $itemMode,
            false,
            $actor,
        );

        $newPayload = $result['payload'] ?? $payload;

        if (!empty($result['waiting'])) {
            $wakeAt = isset($result['wakeAt']) ? (string) $result['wakeAt'] : null;
            $this->finishItem(
                $item,
                AutomationRunItem::STATUS_WAITING,
                null,
                $result['log'],
                $newPayload,
                $wakeAt
            );

            return;
        }

        if ($result['ok']) {
            $this->finishItem($item, AutomationRunItem::STATUS_DONE, null, $result['log'], $newPayload);
            $this->bumpRunCounter((string) $run->getId(), 'doneCount');

            return;
        }

        if ($onFailure !== []) {
            try {
                $this->actionRunner->runActions(
                    $onFailure,
                    $automation,
                    $run,
                    $item,
                    $target,
                    $tenantId,
                    $newPayload,
                    'allMatching',
                    false,
                    $actor,
                );
            } catch (Throwable $e) {
                $this->log->warning('onFailure: ' . $e->getMessage());
            }
        }

        $this->finishItem(
            $item,
            AutomationRunItem::STATUS_FAILED,
            $result['error'] ?? 'failed',
            $result['log'],
            $newPayload
        );
        $this->bumpRunCounter((string) $run->getId(), 'failedCount');
    }

    private function claimItem(Entity $item): bool
    {
        $expected = $item->get('status');
        if (!in_array($expected, [
            AutomationRunItem::STATUS_PENDING,
            AutomationRunItem::STATUS_RETRY,
        ], true)) {
            return false;
        }

        $now = date('Y-m-d H:i:s');
        $update = $this->entityManager
            ->getQueryBuilder()
            ->update()
            ->in(AutomationRunItem::ENTITY_TYPE)
            ->set([
                'status' => AutomationRunItem::STATUS_PROCESSING,
                'claimedAt' => $now,
            ])
            ->where([
                'id' => $item->getId(),
                'status' => $expected,
            ])
            ->build();

        $sth = $this->entityManager->getQueryExecutor()->execute($update);
        if ($sth->rowCount() === 0) {
            return false;
        }

        $item->set([
            'status' => AutomationRunItem::STATUS_PROCESSING,
            'claimedAt' => $now,
        ]);

        return true;
    }

    /**
     * @param list<array<string, mixed>> $actionLog
     * @param array<string, mixed>|null $payload
     */
    private function finishItem(
        Entity $item,
        string $status,
        ?string $error,
        array $actionLog,
        ?array $payload,
        ?string $wakeAt = null,
    ): void {
        $data = [
            'status' => $status,
            'errorMessage' => $error ? substr($error, 0, 5000) : null,
            'actionLog' => $actionLog,
        ];

        if ($payload !== null) {
            $data['payload'] = $payload;
        }

        if ($status === AutomationRunItem::STATUS_WAITING) {
            $data['wakeAt'] = $wakeAt;
            $data['finishedAt'] = null;
        } else {
            $data['finishedAt'] = date('Y-m-d H:i:s');
            $data['wakeAt'] = null;
        }

        $item->set($data);
        $this->entityManager->saveEntity($item, [SaveOption::SKIP_ALL => true]);
    }

    private function bumpRunCounter(string $runId, string $field): void
    {
        $run = $this->entityManager->getEntityById(AutomationRun::ENTITY_TYPE, $runId);
        if (!$run) {
            return;
        }

        $run->set($field, (int) $run->get($field) + 1);
        $this->entityManager->saveEntity($run, [SaveOption::SKIP_ALL => true]);
    }

    private function dispatchChunks(string $runId): void
    {
        $pending = $this->entityManager
            ->getRDBRepository(AutomationRunItem::ENTITY_TYPE)
            ->where([
                'runId' => $runId,
                'status' => [
                    AutomationRunItem::STATUS_PENDING,
                    AutomationRunItem::STATUS_RETRY,
                ],
            ])
            ->order('createdAt')
            ->limit(0, 500)
            ->find();

        $ids = [];
        foreach ($pending as $item) {
            $ids[] = $item->getId();
        }

        foreach (array_chunk($ids, self::CHUNK_SIZE) as $chunk) {
            $this->jobSchedulerFactory
                ->create()
                ->setClassName(ProcessAutomationRunChunk::class)
                ->setQueue(QueueName::E0)
                ->setData([
                    'runId' => $runId,
                    'itemIds' => $chunk,
                ])
                ->schedule();
        }
    }

    public function finalizeRunIfComplete(string $runId): void
    {
        $open = $this->entityManager
            ->getRDBRepository(AutomationRunItem::ENTITY_TYPE)
            ->where([
                'runId' => $runId,
                'status' => [
                    AutomationRunItem::STATUS_PENDING,
                    AutomationRunItem::STATUS_RETRY,
                    AutomationRunItem::STATUS_PROCESSING,
                    AutomationRunItem::STATUS_WAITING,
                ],
            ])
            ->count();

        if ($open !== 0) {
            return;
        }

        $run = $this->entityManager->getEntityById(AutomationRun::ENTITY_TYPE, $runId);
        if (!$run || $run->get('status') !== AutomationRun::STATUS_RUNNING) {
            return;
        }

        $automation = $this->entityManager->getEntityById(
            Automation::ENTITY_TYPE,
            (string) $run->get('automationId')
        );

        if ($automation) {
            try {
                $def = $this->validator->normalizeAndValidate($automation);
            } catch (Throwable $e) {
                $this->log->warning('finalize normalize: ' . $e->getMessage());
                $def = null;
            }

            if (is_array($def) && ($def['kind'] ?? '') === 'batch') {
                $stages = $this->batchStages($def);
                $stageIndex = (int) ($run->get('stageIndex') ?? 0);
                $nextIndex = $stageIndex + 1;

                // Opt-in: export public payload keys from completed stage into run.dataBag
                // before the next stage materializes (so importRunBag can read them).
                try {
                    $completedStage = $stages[$stageIndex] ?? null;
                    if (is_array($completedStage)) {
                        $exportCfg = $this->runDataBag->normalizeExportConfig(
                            $completedStage['exportToRunBag'] ?? null
                        );
                        if ($exportCfg !== null) {
                            $stageId = (string) ($completedStage['id'] ?? ('s' . $stageIndex));
                            $this->runDataBag->exportStageToRun($run, $stageId, $exportCfg);
                            $run = $this->entityManager->getEntityById(AutomationRun::ENTITY_TYPE, $runId)
                                ?: $run;
                        }
                    }
                } catch (Throwable $e) {
                    $this->log->warning('RunDataBag export: ' . $e->getMessage());
                    $run->set([
                        'status' => AutomationRun::STATUS_FAILED,
                        'errorSummary' => 'exportToRunBag: ' . $e->getMessage(),
                        'completedAt' => date('Y-m-d H:i:s'),
                    ]);
                    $this->entityManager->saveEntity($run, [SaveOption::SKIP_ALL => true]);
                    if ($automation) {
                        $this->touchAutomationAfterRun($automation, 'Failed');
                    }

                    return;
                }

                if ($nextIndex < count($stages)) {
                    // CAS-style advance to avoid double-begin from concurrent finalizers
                    $advanced = $this->tryAdvanceStageIndex($runId, $stageIndex, $nextIndex);
                    if (!$advanced) {
                        return;
                    }

                    $run = $this->entityManager->getEntityById(AutomationRun::ENTITY_TYPE, $runId);
                    if (!$run || $run->get('status') !== AutomationRun::STATUS_RUNNING) {
                        return;
                    }

                    $actor = $this->identity->fromRun($run);
                    $tenantId = $run->get('tenantId') ? (string) $run->get('tenantId') : null;
                    $teamsIds = $run->get('teamsIds') ?: [];
                    if (!is_array($teamsIds)) {
                        $teamsIds = [];
                    }
                    $triggerPayload = $this->normalizePayload($run->get('triggerPayload'));

                    try {
                        $this->beginBatchStage(
                            $run,
                            $automation,
                            $def,
                            $nextIndex,
                            $tenantId,
                            $teamsIds,
                            $triggerPayload,
                            $actor,
                        );
                    } catch (Throwable $e) {
                        $run->set([
                            'status' => AutomationRun::STATUS_FAILED,
                            'errorSummary' => $e->getMessage(),
                            'completedAt' => date('Y-m-d H:i:s'),
                        ]);
                        $this->entityManager->saveEntity($run, [SaveOption::SKIP_ALL => true]);
                        $this->touchAutomationAfterRun($automation, 'Failed');

                        return;
                    }

                    // Recurse: empty stages keep advancing; else dispatch new work.
                    $this->finalizeRunIfComplete($runId);

                    $run = $this->entityManager->getEntityById(AutomationRun::ENTITY_TYPE, $runId);
                    if ($run && $run->get('status') === AutomationRun::STATUS_RUNNING) {
                        $this->dispatchChunks($runId);
                    }

                    return;
                }
            }
        }

        $failed = (int) $run->get('failedCount');
        $status = $failed > 0 && (int) $run->get('doneCount') === 0
            ? AutomationRun::STATUS_FAILED
            : AutomationRun::STATUS_COMPLETED;

        $run->set([
            'status' => $status,
            'completedAt' => date('Y-m-d H:i:s'),
        ]);
        $this->entityManager->saveEntity($run, [SaveOption::SKIP_ALL => true]);

        if ($automation) {
            $this->touchAutomationAfterRun(
                $automation,
                $status === AutomationRun::STATUS_FAILED ? 'Failed' : 'Completed'
            );
        }

        try {
            $parentRunIds = $this->joinCoordinator->onChildRunTerminal($runId);
            foreach ($parentRunIds as $parentRunId) {
                $this->dispatchChunks($parentRunId);
            }
        } catch (Throwable $e) {
            $this->log->warning('JoinCoordinator: ' . $e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $def
     * @param list<string> $teamsIds
     * @param array<string, mixed> $triggerPayload
     */
    private function beginBatchStage(
        Entity $run,
        Entity $automation,
        array $def,
        int $stageIndex,
        ?string $automationTenantId,
        array $teamsIds,
        array $triggerPayload,
        User $actor,
    ): void {
        $stages = $this->batchStages($def);
        if (!isset($stages[$stageIndex])) {
            return;
        }

        $stage = $stages[$stageIndex];
        $stageId = (string) ($stage['id'] ?? ('s' . $stageIndex));
        $scope = (string) ($stage['scope'] ?? 'forEach');

        $run->set('stageIndex', $stageIndex);
        $this->entityManager->saveEntity($run, [SaveOption::SKIP_ALL => true]);

        if ($scope === 'once') {
            $row = $this->buildOnceRow($automation, $def, $run, $triggerPayload, $actor);
            $row = $this->applyImportRunBag($row, $run, $stage);
            $count = $this->persistRunItems(
                $run,
                $automation,
                [$row],
                $automationTenantId,
                $teamsIds,
                $stageId,
            );
        } else {
            $mapDef = [
                'map' => $stage['map'] ?? [],
                'limits' => $def['limits'] ?? [],
            ];
            $materializePayload = array_merge($triggerPayload, [
                '_run' => $this->buildRunStats($run, $stageIndex, $stageId, $scope),
                '_stageId' => $stageId,
                '_stageIndex' => $stageIndex,
            ]);
            $rows = $this->mapMaterializer->materialize(
                $mapDef,
                $automationTenantId,
                $materializePayload,
                $actor,
                $this->crossTenantAccess->isAuthorized($automation, $actor),
            );
            $importCfg = $this->runDataBag->normalizeImportConfig($stage['importRunBag'] ?? null);
            if ($importCfg !== null) {
                $bag = $this->runDataBag->read($run);
                foreach ($rows as $i => $row) {
                    $rows[$i] = $this->seedRowFromRunBag($row, $bag, $importCfg);
                }
            }
            $count = $this->persistRunItems(
                $run,
                $automation,
                $rows,
                $automationTenantId,
                $teamsIds,
                $stageId,
            );
        }

        $prev = (int) ($run->get('itemCount') ?? 0);
        $run->set('itemCount', $prev + $count);
        $this->entityManager->saveEntity($run, [SaveOption::SKIP_ALL => true]);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param list<string> $teamsIds
     */
    private function persistRunItems(
        Entity $run,
        Entity $automation,
        array $rows,
        ?string $automationTenantId,
        array $teamsIds,
        string $stageId,
    ): int {
        $count = 0;
        foreach ($rows as $row) {
            $item = $this->entityManager->getNewEntity(AutomationRunItem::ENTITY_TYPE);
            $item->set([
                'runId' => $run->getId(),
                'automationId' => $automation->getId(),
                'targetType' => $row['targetType'],
                'targetId' => $row['targetId'],
                'tenantId' => $row['tenantId'] ?? $automationTenantId,
                'payload' => $row['payload'] ?? [],
                'stageId' => $stageId,
                'status' => AutomationRunItem::STATUS_PENDING,
                'teamsIds' => $teamsIds,
                'retryCount' => 0,
            ]);

            try {
                $this->entityManager->saveEntity($item, [SaveOption::SKIP_ALL => true]);
                $count++;
            } catch (Throwable $e) {
                $this->log->warning('AutomationRunner item save: ' . $e->getMessage());
            }
        }

        return $count;
    }

    /**
     * @param array<string, mixed> $def
     * @param array<string, mixed> $triggerPayload
     * @return array{targetType: string, targetId: string, tenantId: ?string, payload: array<string, mixed>}
     */
    private function buildOnceRow(
        Entity $automation,
        array $def,
        ?Entity $run,
        array $triggerPayload,
        User $actor,
    ): array {
        $tenantId = $automation->get('tenantId') ? (string) $automation->get('tenantId') : null;
        if ($run && $run->get('tenantId')) {
            $tenantId = (string) $run->get('tenantId');
        }

        $targetType = null;
        $targetId = null;

        if ($tenantId) {
            $tenant = $this->entityManager->getEntityById('Tenant', $tenantId);
            if ($tenant) {
                $targetType = 'Tenant';
                $targetId = $tenantId;
            }
        }

        if ($targetType === null) {
            $targetType = 'User';
            $targetId = $actor->getId();
        }

        $stageIndex = $run ? (int) ($run->get('stageIndex') ?? 0) : 0;
        $stages = $this->batchStages($def);
        $stageId = (string) (($stages[$stageIndex]['id'] ?? null) ?: ('s' . $stageIndex));

        $payload = [
            '_trigger' => $triggerPayload,
            '_scope' => 'once',
            '_stageId' => $stageId,
            '_stageIndex' => $stageIndex,
            '_run' => $run
                ? $this->buildRunStats($run, $stageIndex, $stageId, 'once')
                : [
                    'itemCount' => 0,
                    'doneCount' => 0,
                    'failedCount' => 0,
                    'skippedCount' => 0,
                    'stageIndex' => $stageIndex,
                    'stageId' => $stageId,
                    'scope' => 'once',
                ],
        ];

        return [
            'targetType' => $targetType,
            'targetId' => $targetId,
            'tenantId' => $tenantId,
            'payload' => $payload,
        ];
    }

    /**
     * @param array{targetType: string, targetId: string, tenantId: ?string, payload: array<string, mixed>} $row
     * @param array<string, mixed> $stage
     * @return array{targetType: string, targetId: string, tenantId: ?string, payload: array<string, mixed>}
     */
    private function applyImportRunBag(array $row, Entity $run, array $stage): array
    {
        $importCfg = $this->runDataBag->normalizeImportConfig($stage['importRunBag'] ?? null);
        if ($importCfg === null) {
            return $row;
        }

        return $this->seedRowFromRunBag($row, $this->runDataBag->read($run), $importCfg);
    }

    /**
     * @param array{targetType: string, targetId: string, tenantId: ?string, payload: array<string, mixed>} $row
     * @param array<string, mixed> $bag
     * @param array{keys: list<string>|true, into: string, overwrite: bool} $importCfg
     * @return array{targetType: string, targetId: string, tenantId: ?string, payload: array<string, mixed>}
     */
    private function seedRowFromRunBag(array $row, array $bag, array $importCfg): array
    {
        $payload = $row['payload'] ?? [];
        if (!is_array($payload)) {
            $payload = [];
        }
        $row['payload'] = $this->runDataBag->seedPayload(
            $payload,
            $bag,
            $importCfg['keys'],
            $importCfg['into'],
            $importCfg['overwrite'],
        );

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildRunStats(Entity $run, int $stageIndex, string $stageId, string $scope): array
    {
        return [
            'runId' => (string) $run->getId(),
            'itemCount' => (int) ($run->get('itemCount') ?? 0),
            'doneCount' => (int) ($run->get('doneCount') ?? 0),
            'failedCount' => (int) ($run->get('failedCount') ?? 0),
            'skippedCount' => (int) ($run->get('skippedCount') ?? 0),
            'stageIndex' => $stageIndex,
            'stageId' => $stageId,
            'scope' => $scope,
        ];
    }

    private function tryAdvanceStageIndex(string $runId, int $from, int $to): bool
    {
        $update = $this->entityManager
            ->getQueryBuilder()
            ->update()
            ->in(AutomationRun::ENTITY_TYPE)
            ->set(['stageIndex' => $to])
            ->where([
                'id' => $runId,
                'status' => AutomationRun::STATUS_RUNNING,
                'stageIndex' => $from,
            ])
            ->build();

        $sth = $this->entityManager->getQueryExecutor()->execute($update);

        return $sth->rowCount() > 0;
    }

    /**
     * @param array<string, mixed> $def
     * @return list<array<string, mixed>>
     */
    private function batchStages(array $def): array
    {
        $stages = $def['stages'] ?? null;
        if (is_array($stages) && $stages !== []) {
            $out = [];
            foreach ($stages as $s) {
                if (is_array($s)) {
                    $out[] = $s;
                }
            }

            return $out;
        }

        // Fallback if validation not applied
        if (!empty($def['map']) && is_array($def['map'])) {
            return [[
                'id' => 's0',
                'scope' => 'forEach',
                'map' => $def['map'],
                'actions' => $def['actions'] ?? [],
                'onFailure' => $def['onFailure'] ?? [],
                'itemMode' => $def['itemMode'] ?? 'allMatching',
            ]];
        }

        return [];
    }

    /**
     * @param array<string, mixed> $def
     * @return array<string, mixed>|null
     */
    private function firstStage(array $def): ?array
    {
        $stages = $this->batchStages($def);

        return $stages[0] ?? null;
    }

    /**
     * @param array<string, mixed> $def
     * @return array<string, mixed>
     */
    private function stageForItem(array $def, Entity $item): array
    {
        $stages = $this->batchStages($def);
        $stageId = (string) ($item->get('stageId') ?? '');

        if ($stageId !== '') {
            foreach ($stages as $st) {
                if ((string) ($st['id'] ?? '') === $stageId) {
                    return $st;
                }
            }
        }

        $idx = 0;
        // Prefer stageIndex from run if accessible via payload
        $payload = $this->normalizePayload($item->get('payload'));
        if (isset($payload['_stageIndex'])) {
            $idx = (int) $payload['_stageIndex'];
        }

        return $stages[$idx] ?? ($stages[0] ?? [
            'actions' => $def['actions'] ?? [],
            'onFailure' => $def['onFailure'] ?? [],
            'itemMode' => $def['itemMode'] ?? 'allMatching',
            'scope' => 'forEach',
            'id' => 's0',
        ]);
    }

    private function touchAutomationAfterRun(Entity $automation, string $lastStatus): void
    {
        $scheduling = (string) ($automation->get('scheduling') ?? '');
        $next = null;
        if (
            $automation->get('triggerType') === Automation::TRIGGER_SCHEDULE &&
            $scheduling !== '' &&
            $automation->get('status') === Automation::STATUS_ACTIVE
        ) {
            $next = $this->scheduleHelper->nextRunAt(
                $scheduling,
                (string) ($automation->get('timezone') ?: 'UTC'),
                null,
                $this->getScheduleStartAt($automation),
            );
        }

        $patch = [
            'lastRunStatus' => $lastStatus,
            'nextRunAt' => $next,
        ];

        if ($lastStatus === 'Running') {
            $patch['lastRunAt'] = date('Y-m-d H:i:s');
            $patch['runCount'] = (int) $automation->get('runCount') + 1;
        }

        $automation->set($patch);
        $this->entityManager->saveEntity($automation, [SaveOption::SKIP_ALL => true]);
    }

    private function getScheduleStartAt(Entity $automation): ?\DateTimeImmutable
    {
        foreach (['activatedAt', 'createdAt'] as $field) {
            $value = $automation->get($field);
            if (!$value) {
                continue;
            }

            try {
                return new \DateTimeImmutable((string) $value, new \DateTimeZone('UTC'));
            } catch (Throwable) {
                continue;
            }
        }

        return null;
    }

    private function wakeWaitingItems(): void
    {
        $now = date('Y-m-d H:i:s');
        $waiting = $this->entityManager
            ->getRDBRepository(AutomationRunItem::ENTITY_TYPE)
            ->where([
                'status' => AutomationRunItem::STATUS_WAITING,
                'wakeAt<=' => $now,
            ])
            ->order('wakeAt')
            ->limit(0, 200)
            ->find();

        $runIds = [];
        foreach ($waiting as $item) {
            $item->set([
                'status' => AutomationRunItem::STATUS_RETRY,
                'claimedAt' => null,
            ]);
            $this->entityManager->saveEntity($item, [SaveOption::SKIP_ALL => true]);
            $runId = (string) $item->get('runId');
            $runIds[$runId] = true;
        }

        foreach (array_keys($runIds) as $runId) {
            $this->dispatchChunks($runId);
        }
    }

    private function recoverStaleClaims(): void
    {
        $threshold = date('Y-m-d H:i:s', time() - 900);
        $stale = $this->entityManager
            ->getRDBRepository(AutomationRunItem::ENTITY_TYPE)
            ->where([
                'status' => AutomationRunItem::STATUS_PROCESSING,
                'claimedAt<' => $threshold,
            ])
            ->limit(0, 100)
            ->find();

        foreach ($stale as $item) {
            $item->set([
                'status' => AutomationRunItem::STATUS_RETRY,
                'retryCount' => (int) $item->get('retryCount') + 1,
                'errorMessage' => 'stale claim recovered',
            ]);
            $this->entityManager->saveEntity($item, [SaveOption::SKIP_ALL => true]);
        }
    }

    /**
     * @param array<string, mixed> $def
     * @param array<string, mixed> $triggerPayload
     * @return list<array{targetType: string, targetId: string, tenantId: ?string, payload: array<string, mixed>}>
     */
    private function materializeMachineItems(
        Entity $automation,
        array $def,
        ?string $automationTenantId,
        array $triggerPayload,
        User $actor,
    ): array {
        $type = (string) ($triggerPayload['entityType'] ?? '');
        $id = (string) ($triggerPayload['entityId'] ?? '');

        if ($type !== '' && $id !== '') {
            return [$this->machineItemFromSubject(
                $type,
                $id,
                $automationTenantId,
                $triggerPayload,
                $def,
                $actor,
            )];
        }

        // Schedule / bulk path: materialize subjects from subjectEntityType + filter.
        $type = $this->triggerFilterEvaluator->resolveSubjectEntityType($automation);
        if ($type === '') {
            throw new Error(
                'Machine run requires triggerPayload.entityType and entityId, '
                . 'or subjectEntityType with a filter for schedule/bulk runs.'
            );
        }

        $limits = is_array($def['limits'] ?? null) ? $def['limits'] : [];
        $maxItems = (int) ($limits['maxItems'] ?? 500);
        $maxItems = max(1, min(2000, $maxItems));

        $subjects = $this->triggerFilterEvaluator->findMatchingSubjects(
            $automation,
            $type,
            $automationTenantId,
            $maxItems,
            $triggerPayload,
            $actor,
            $this->crossTenantAccess->isAuthorized($automation, $actor),
        );

        $items = [];
        foreach ($subjects as $target) {
            $items[] = $this->machineItemFromEntity(
                $target,
                $automationTenantId,
                array_merge($triggerPayload, [
                    'entityType' => $target->getEntityType(),
                    'entityId' => $target->getId(),
                ]),
                $def,
            );
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $triggerPayload
     * @param array<string, mixed> $def
     * @return array{targetType: string, targetId: string, tenantId: ?string, payload: array<string, mixed>}
     */
    private function machineItemFromSubject(
        string $type,
        string $id,
        ?string $automationTenantId,
        array $triggerPayload,
        array $def,
        User $actor,
    ): array {
        $target = $this->entityManager->getEntityById($type, $id);
        if (!$target) {
            throw new Error("Machine subject {$type}/{$id} not found.");
        }

        try {
            if (!$this->aclManager->createUserAcl($actor)->check($target, 'read')) {
                throw new Error("Machine subject {$type}/{$id} not readable by run-as user.");
            }
        } catch (Error $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new Error("Machine subject {$type}/{$id} ACL check failed: " . $e->getMessage());
        }

        return $this->machineItemFromEntity($target, $automationTenantId, $triggerPayload, $def);
    }

    /**
     * @param array<string, mixed> $triggerPayload
     * @param array<string, mixed> $def
     * @return array{targetType: string, targetId: string, tenantId: ?string, payload: array<string, mixed>}
     */
    private function machineItemFromEntity(
        Entity $target,
        ?string $automationTenantId,
        array $triggerPayload,
        array $def,
    ): array {
        $tenantId = $automationTenantId;
        if (!$tenantId && $target->hasAttribute('tenantId') && $target->get('tenantId')) {
            $tenantId = (string) $target->get('tenantId');
        }

        if ($tenantId && $target->getEntityType() !== 'User' && $target->getEntityType() !== 'Tenant') {
            $this->tenantGuard->assertEntityTenant($target, $tenantId, 'machine-subject');
        }

        return [
            'targetType' => $target->getEntityType(),
            'targetId' => (string) $target->getId(),
            'tenantId' => $tenantId,
            'payload' => [
                '_trigger' => $triggerPayload,
                '_state' => $def['initial'] ?? null,
                '_depth' => (int) ($triggerPayload['_depth'] ?? 0),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizePayload(mixed $payload): array
    {
        if ($payload instanceof \stdClass) {
            $payload = json_decode(json_encode($payload) ?: '{}', true) ?: [];
        }

        return is_array($payload) ? $payload : [];
    }

    private function getAutomation(string $id): Entity
    {
        $automation = $this->entityManager->getEntityById(Automation::ENTITY_TYPE, $id);
        if (!$automation) {
            throw new NotFound("Automation {$id} not found.");
        }

        return $automation;
    }
}

<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureAutomation\Services;

use Espo\Core\InjectableFactory;
use Espo\Core\ORM\Repository\Option\SaveOption;
use Espo\Core\Utils\Log;
use Espo\Core\Utils\Metadata;
use Espo\Entities\User;
use Espo\Modules\FeatureJourney\Classes\JourneyActions\Action as JourneyAction;
use Espo\Modules\FeatureJourney\Services\ActionContext as JourneyActionContext;
use Espo\Modules\FeatureJourney\Services\FormulaReadScope;
use Espo\Modules\FeatureJourney\Services\PeriodParser;
use Espo\Modules\FeatureJourney\Services\RestrictedFormulaRunner;
use Espo\Modules\FeatureJourney\Services\TenantGuard;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use stdClass;
use Throwable;

/**
 * Runs action lists for one AutomationRunItem.
 * Supports dry-run planning, waitJoin / waitUntil parks, and action idempotency/debounce.
 */
class AutomationActionRunner
{
    public function __construct(
        private EntityManager $entityManager,
        private Metadata $metadata,
        private InjectableFactory $injectableFactory,
        private TenantGuard $tenantGuard,
        private RestrictedFormulaRunner $formulaRunner,
        private ActionReceiptStore $receiptStore,
        private JoinCoordinator $joinCoordinator,
        private PeriodParser $periodParser,
        private WakeAtResolver $wakeAtResolver,
        private Log $log,
    ) {}

    /**
     * @param list<array<string, mixed>> $actions
     * @param array<string, mixed> $payload
     * @return array{
     *   ok: bool,
     *   log: list<array<string, mixed>>,
     *   error?: string,
     *   waiting?: bool,
     *   wakeAt?: ?string,
     *   payload?: array<string, mixed>
     * }
     */
    public function runActions(
        array $actions,
        Entity $automation,
        Entity $run,
        Entity $item,
        Entity $target,
        string $tenantId,
        array $payload,
        string $itemMode = 'allMatching',
        bool $dryRun = false,
        ?User $actor = null,
    ): array {
        $log = [];
        $matchedOnce = false;

        foreach ($actions as $index => $action) {
            $type = (string) ($action['type'] ?? '');
            $when = $action['when'] ?? null;

            if (is_string($when) && trim($when) !== '') {
                try {
                    $pass = $this->evalWhen($when, $target, $item, $automation, $tenantId, $payload, $actor);
                } catch (Throwable $e) {
                    $log[] = [
                        'index' => $index,
                        'type' => $type,
                        'status' => 'error',
                        'error' => 'when: ' . $e->getMessage(),
                    ];

                    if (!(bool) ($action['continueOnError'] ?? false)) {
                        return ['ok' => false, 'log' => $log, 'error' => $e->getMessage(), 'payload' => $payload];
                    }

                    continue;
                }

                if (!$pass) {
                    $log[] = [
                        'index' => $index,
                        'type' => $type,
                        'status' => 'skipped_when',
                    ];
                    continue;
                }
            }

            if ($itemMode === 'firstMatch' && $matchedOnce) {
                $log[] = [
                    'index' => $index,
                    'type' => $type,
                    'status' => 'skipped_first_match',
                ];
                continue;
            }

            $matchedOnce = true;

            $params = $action['params'] ?? [];
            if ($params instanceof stdClass) {
                $params = (array) $params;
            }
            if (!is_array($params)) {
                $params = [];
            }
            unset($params['tenantId']);

            $paramFormulas = $action['paramFormulas'] ?? [];
            if ($paramFormulas instanceof stdClass) {
                $paramFormulas = (array) $paramFormulas;
            }
            if (!is_array($paramFormulas)) {
                $paramFormulas = [];
            }

            try {
                $params = $this->resolveParamFormulas(
                    $params,
                    $paramFormulas,
                    $target,
                    $item,
                    $automation,
                    $tenantId,
                    $payload,
                    $actor,
                );
            } catch (Throwable $e) {
                $log[] = [
                    'index' => $index,
                    'type' => $type,
                    'status' => 'error',
                    'error' => 'paramFormulas: ' . $e->getMessage(),
                ];

                if (!(bool) ($action['continueOnError'] ?? false)) {
                    return ['ok' => false, 'log' => $log, 'error' => $e->getMessage(), 'payload' => $payload];
                }

                continue;
            }

            // —— waitJoin special path ——
            if ($type === 'waitJoin') {
                if ($dryRun) {
                    $log[] = [
                        'index' => $index,
                        'type' => $type,
                        'status' => 'would_wait_join',
                        'params' => $params,
                    ];
                    continue;
                }

                $joinResult = $this->handleWaitJoin($params, $payload, $item);
                $payload = $joinResult['payload'];
                $log[] = [
                    'index' => $index,
                    'type' => $type,
                    'status' => $joinResult['satisfied'] ? 'join_ok' : 'waiting_join',
                    'mode' => $joinResult['mode'],
                    'stats' => $payload['_joinStats'] ?? null,
                ];

                if (!$joinResult['satisfied']) {
                    return [
                        'ok' => true,
                        'log' => $log,
                        'waiting' => true,
                        'wakeAt' => $joinResult['wakeAt'],
                        'payload' => $payload,
                    ];
                }

                continue;
            }

            // —— waitUntil special path ——
            if ($type === 'waitUntil') {
                $untilResult = $this->handleWaitUntil($params, $payload, $item, $dryRun);
                $payload = $untilResult['payload'];
                $log[] = [
                    'index' => $index,
                    'type' => $type,
                    'status' => $untilResult['status'],
                    'wakeAt' => $untilResult['wakeAt'],
                ];

                if ($untilResult['waiting']) {
                    return [
                        'ok' => true,
                        'log' => $log,
                        'waiting' => true,
                        'wakeAt' => $untilResult['wakeAt'],
                        'payload' => $payload,
                    ];
                }

                if (!$untilResult['ok']) {
                    if (!(bool) ($action['continueOnError'] ?? false)) {
                        return [
                            'ok' => false,
                            'log' => $log,
                            'error' => $untilResult['error'] ?? 'waitUntil failed',
                            'payload' => $payload,
                        ];
                    }
                }

                continue;
            }

            // —— dry-run: plan only (payload writers still apply for simulate chaining) ——
            if ($dryRun) {
                if (in_array($type, ['runReport', 'setPayload', 'assign', 'exportToRunBag'], true)) {
                    try {
                        // Coerce maxRows if provided as string from UI.
                        if ($type === 'runReport' && isset($params['maxRows'])) {
                            $params['maxRows'] = (int) $params['maxRows'];
                        }
                        $this->runOne($type, $params, $automation, $run, $item, $target, $tenantId, $actor);
                        $payload = $this->readItemPayload($item, $payload);
                        $log[] = [
                            'index' => $index,
                            'type' => $type,
                            'status' => 'would_run',
                            'params' => $params,
                            'payloadKeys' => array_keys($payload),
                        ];
                    } catch (Throwable $e) {
                        $log[] = [
                            'index' => $index,
                            'type' => $type,
                            'status' => 'error',
                            'error' => $e->getMessage(),
                        ];
                        if (!(bool) ($action['continueOnError'] ?? false)) {
                            return [
                                'ok' => false,
                                'log' => $log,
                                'error' => $e->getMessage(),
                                'payload' => $payload,
                            ];
                        }
                    }
                    continue;
                }

                $log[] = [
                    'index' => $index,
                    'type' => $type,
                    'status' => 'would_run',
                    'params' => $params,
                ];
                continue;
            }

            // —— idempotency / debounce ——
            $idempKey = $this->resolveIdempotencyKey(
                $action,
                $type,
                $params,
                $target,
                $item,
                $automation,
                $tenantId,
                $payload,
            );
            $debounce = isset($action['debounce']) && is_string($action['debounce'])
                ? trim($action['debounce'])
                : '';

            if ($idempKey !== null) {
                $claimed = $this->receiptStore->tryClaim(
                    (string) $automation->getId(),
                    $idempKey,
                    'action',
                    $debounce !== '' ? $debounce : null,
                    $tenantId,
                    $item->getId() ? (string) $item->getId() : null,
                    $type,
                );

                if (!$claimed) {
                    $log[] = [
                        'index' => $index,
                        'type' => $type,
                        'status' => 'skipped_idempotent',
                        'key' => $idempKey,
                    ];
                    continue;
                }
            }

            $maxRetries = max(0, min(5, (int) ($action['maxRetries'] ?? 0)));
            $lastError = null;
            $ok = false;

            for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
                try {
                    if ($type === 'runReport' && isset($params['maxRows'])) {
                        $params['maxRows'] = (int) $params['maxRows'];
                    }
                    $this->runOne($type, $params, $automation, $run, $item, $target, $tenantId, $actor);
                    // Refresh payload after side effects that mutate item (e.g. startChild, setPayload)
                    $payload = $this->readItemPayload($item, $payload);
                    $ok = true;
                    break;
                } catch (Throwable $e) {
                    $lastError = $e->getMessage();
                    $this->log->error(
                        "AutomationActionRunner: {$type} attempt " . ($attempt + 1) .
                        " failed: {$lastError}"
                    );
                }
            }

            if ($ok) {
                $log[] = [
                    'index' => $index,
                    'type' => $type,
                    'status' => 'ok',
                ];
                continue;
            }

            if ($idempKey !== null) {
                $this->receiptStore->release((string) $automation->getId(), $idempKey, 'action');
            }

            $log[] = [
                'index' => $index,
                'type' => $type,
                'status' => 'error',
                'error' => $lastError,
            ];

            if (!(bool) ($action['continueOnError'] ?? false)) {
                return [
                    'ok' => false,
                    'log' => $log,
                    'error' => $lastError ?? 'action_failed',
                    'payload' => $payload,
                ];
            }
        }

        return ['ok' => true, 'log' => $log, 'payload' => $payload];
    }

    /**
     * Plan actions without side effects (simulate).
     *
     * @param list<array<string, mixed>> $actions
     * @param array<string, mixed> $payload
     * @return array{ok: bool, log: list<array<string, mixed>>, payload: array<string, mixed>}
     */
    public function planActions(
        array $actions,
        Entity $automation,
        Entity $target,
        string $tenantId,
        array $payload,
        string $itemMode = 'allMatching',
        ?User $actor = null,
    ): array {
        $run = $this->entityManager->getNewEntity('AutomationRun');
        $item = $this->entityManager->getNewEntity('AutomationRunItem');
        $item->set('id', 'sim_' . substr(md5((string) microtime(true)), 0, 14));
        // Seed the ephemeral item with the materialized map payload. Payload-writing
        // actions (runReport/setPayload/assign) merge into the item payload and
        // readItemPayload() then returns it verbatim, so without this seed the map
        // steps would be dropped and any later formula referencing them would fail
        // in preview only — while the real run, whose item is persisted, succeeds.
        $item->set('payload', $payload);

        $result = $this->runActions(
            $actions,
            $automation,
            $run,
            $item,
            $target,
            $tenantId,
            $payload,
            $itemMode,
            true,
            $actor,
        );

        return [
            'ok' => $result['ok'],
            'log' => $result['log'],
            'payload' => $result['payload'] ?? $payload,
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $payload
     * @return array{satisfied: bool, wakeAt: ?string, mode: string, payload: array<string, mixed>}
     */
    private function handleWaitJoin(array $params, array $payload, Entity $item): array
    {
        $mode = (string) ($params['mode'] ?? 'waitAll');
        if (!in_array($mode, ['waitAll', 'waitAny'], true)) {
            $mode = 'waitAll';
        }

        $eval = $this->joinCoordinator->evaluate($payload, $mode);
        $payload = $eval['payload'];
        $payload['_joinMode'] = $mode;

        // Persist join snapshot on item for coordinator refreshes
        if ($item->hasId()) {
            $item->set('payload', $payload);
            try {
                $this->entityManager->saveEntity($item, [SaveOption::SKIP_ALL => true]);
            } catch (Throwable) {
            }
        }

        if ($eval['satisfied']) {
            return [
                'satisfied' => true,
                'wakeAt' => null,
                'mode' => $mode,
                'payload' => $payload,
            ];
        }

        $timeout = trim((string) ($params['timeoutPeriod'] ?? '2 hours'));
        $wakeAt = null;
        try {
            $wakeAt = $this->periodParser->addToNow($timeout !== '' ? $timeout : '2 hours');
        } catch (Throwable) {
            $wakeAt = date('Y-m-d H:i:s', time() + 7200);
        }

        $payload['_wakeAt'] = $wakeAt;
        $payload['_joinWaiting'] = true;

        if ($item->hasId()) {
            $item->set('payload', $payload);
            try {
                $this->entityManager->saveEntity($item, [SaveOption::SKIP_ALL => true]);
            } catch (Throwable) {
            }
        }

        return [
            'satisfied' => false,
            'wakeAt' => $wakeAt,
            'mode' => $mode,
            'payload' => $payload,
        ];
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $payload
     * @return array{
     *   ok: bool,
     *   waiting: bool,
     *   status: string,
     *   wakeAt: ?string,
     *   payload: array<string, mixed>,
     *   error?: string
     * }
     */
    private function handleWaitUntil(array $params, array $payload, Entity $item, bool $dryRun): array
    {
        $at = $params['at'] ?? null;
        if ($at === null || $at === '') {
            return [
                'ok' => false,
                'waiting' => false,
                'status' => 'error',
                'wakeAt' => null,
                'payload' => $payload,
                'error' => 'waitUntil: params.at is required',
            ];
        }

        $tz = trim((string) ($params['timezone'] ?? ''));

        try {
            $wakeAt = $this->wakeAtResolver->toUtcSql($at, $tz !== '' ? $tz : null);
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'waiting' => false,
                'status' => 'error',
                'wakeAt' => null,
                'payload' => $payload,
                'error' => 'waitUntil: ' . $e->getMessage(),
            ];
        }

        if (strtotime($wakeAt) <= time()) {
            unset($payload['_wakeAt']);

            return [
                'ok' => true,
                'waiting' => false,
                'status' => $dryRun ? 'would_wait_elapsed' : 'wait_elapsed',
                'wakeAt' => $wakeAt,
                'payload' => $payload,
            ];
        }

        if ($dryRun) {
            return [
                'ok' => true,
                'waiting' => false,
                'status' => 'would_wait_until',
                'wakeAt' => $wakeAt,
                'payload' => $payload,
            ];
        }

        $payload['_wakeAt'] = $wakeAt;

        if ($item->hasId()) {
            $item->set('payload', $payload);
            try {
                $this->entityManager->saveEntity($item, [SaveOption::SKIP_ALL => true]);
            } catch (Throwable) {
            }
        }

        return [
            'ok' => true,
            'waiting' => true,
            'status' => 'waiting_until',
            'wakeAt' => $wakeAt,
            'payload' => $payload,
        ];
    }

    /**
     * @param array<string, mixed> $action
     * @param array<string, mixed> $params
     * @param array<string, mixed> $payload
     */
    private function resolveIdempotencyKey(
        array $action,
        string $type,
        array $params,
        Entity $target,
        Entity $item,
        Entity $automation,
        string $tenantId,
        array $payload,
    ): ?string {
        $raw = $action['idempotencyKey'] ?? null;
        if ($raw === null || $raw === false || $raw === '') {
            return null;
        }

        if ($raw === true) {
            // Default key from type + target + stable params subset
            $stable = $params;
            unset($stable['body'], $stable['message'], $stable['description']);
            ksort($stable);

            return implode('|', [
                $type,
                $target->getEntityType(),
                $target->getId(),
                substr(hash('sha256', json_encode($stable) ?: ''), 0, 16),
            ]);
        }

        if (!is_string($raw)) {
            return null;
        }

        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        // Formula when starts with =
        if (str_starts_with($raw, '=')) {
            try {
                $variables = (object) [
                    'automationId' => $automation->getId(),
                    'runItemId' => $item->getId(),
                    'tenantId' => $tenantId,
                    'payload' => json_decode(json_encode($payload) ?: '{}'),
                    'actionType' => $type,
                ];
                $value = $this->formulaRunner->run(
                    substr($raw, 1),
                    $target,
                    $variables,
                    RestrictedFormulaRunner::MODE_CONDITION,
                );

                return $value === null || $value === '' ? null : (string) $value;
            } catch (Throwable $e) {
                $this->log->warning('idempotencyKey formula: ' . $e->getMessage());

                return null;
            }
        }

        // Placeholder template
        $map = [
            '{type}' => $type,
            '{targetType}' => $target->getEntityType(),
            '{targetId}' => $target->getId(),
            '{automationId}' => (string) $automation->getId(),
            '{tenantId}' => $tenantId,
            '{runItemId}' => (string) ($item->getId() ?? ''),
        ];

        return strtr($raw, $map);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function runOne(
        string $type,
        array $params,
        Entity $automation,
        Entity $run,
        Entity $item,
        Entity $target,
        string $tenantId,
        ?User $actor = null,
    ): void {
        if ($target->getEntityType() === 'User') {
            $this->tenantGuard->assertUserInTenant($target->getId(), $tenantId, 'automation-target');
        } elseif ($target->getEntityType() !== 'Tenant') {
            $this->tenantGuard->assertEntityTenant($target, $tenantId, 'automation-target');
        }

        $className = $this->metadata->get(
            ['app', 'automationActionTypes', 'types', $type, 'implementationClassName']
        );

        if (!$className) {
            $className = $this->metadata->get(
                ['app', 'journeyActionTypes', 'types', $type, 'implementationClassName']
            );
        }

        if (!is_string($className) || $className === '' || !class_exists($className)) {
            throw new \Espo\Core\Exceptions\Error("Unknown automation action type: {$type}");
        }

        $ctx = new JourneyActionContext(
            target: $target,
            record: $item,
            stage: $automation,
            journey: $automation,
            trigger: 'automation',
            params: $params,
            tenantId: $tenantId,
            actor: $actor,
        );

        /** @var JourneyAction $impl */
        $impl = $this->injectableFactory->create($className);
        $impl->run($ctx);
    }

    /**
     * @param array<string, mixed> $fallback
     * @return array<string, mixed>
     */
    private function readItemPayload(Entity $item, array $fallback): array
    {
        $live = $this->payloadToArray($item->get('payload'));

        if ($item->hasId()) {
            $id = (string) $item->getId();
            // Simulate / ephemeral ids are not persisted — keep in-memory bag.
            if ($id !== '' && !str_starts_with($id, 'sim_')) {
                $fresh = $this->entityManager->getEntityById($item->getEntityType(), $id);
                if ($fresh) {
                    $db = $this->payloadToArray($fresh->get('payload'));
                    if ($db !== null) {
                        $item->set('payload', $db);

                        return $db;
                    }
                }
            }
        }

        if ($live !== null) {
            return $live;
        }

        return $fallback;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function payloadToArray(mixed $raw): ?array
    {
        if ($raw instanceof stdClass) {
            $raw = json_decode(json_encode($raw) ?: '{}', true) ?: [];
        }

        return is_array($raw) ? $raw : null;
    }

    /**
     * @param array<string, mixed> $params
     * @param array<string, mixed> $formulas
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function resolveParamFormulas(
        array $params,
        array $formulas,
        Entity $target,
        Entity $item,
        Entity $automation,
        string $tenantId,
        array $payload,
        ?User $actor = null,
    ): array {
        if ($formulas === []) {
            return $params;
        }

        $variables = (object) [
            'automationId' => $automation->getId(),
            'runItemId' => $item->getId(),
            'tenantId' => $tenantId,
            'payload' => json_decode(json_encode($payload) ?: '{}'),
        ];

        foreach ($formulas as $key => $script) {
            if (!is_string($key) || $key === '' || $key === 'tenantId' || str_ends_with($key, '.tenantId')) {
                continue;
            }

            if (!is_string($script) || trim($script) === '') {
                continue;
            }

            $value = $this->inReadScope(
                $tenantId,
                $actor,
                fn () => $this->formulaRunner->run(
                    $script,
                    $target,
                    $variables,
                    RestrictedFormulaRunner::MODE_CONDITION,
                ),
            );

            if (!str_contains($key, '.')) {
                $params[$key] = $value;
                continue;
            }

            if (str_starts_with($key, 'fields.')) {
                $fieldKey = substr($key, strlen('fields.'));
                if ($fieldKey === '' || $fieldKey === 'tenantId') {
                    continue;
                }

                if (!isset($params['fields']) || !is_array($params['fields'])) {
                    $params['fields'] = [];
                }

                $params['fields'][$fieldKey] = $value;
                continue;
            }

            $params[$key] = $value;
        }

        return $params;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function evalWhen(
        string $script,
        Entity $target,
        Entity $item,
        Entity $automation,
        string $tenantId,
        array $payload,
        ?User $actor = null,
    ): bool {
        $variables = (object) [
            'automationId' => $automation->getId(),
            'runItemId' => $item->getId(),
            'tenantId' => $tenantId,
            'payload' => json_decode(json_encode($payload) ?: '{}'),
        ];

        $result = $this->inReadScope(
            $tenantId,
            $actor,
            fn () => $this->formulaRunner->run(
                $script,
                $target,
                $variables,
                RestrictedFormulaRunner::MODE_CONDITION,
            ),
        );

        return (bool) $result;
    }

    /**
     * Open the tenant/actor frame that guarded formula reads
     * (scoped\recordAttribute) resolve against.
     *
     * Without an actor there is nobody to check ACL against, so no frame is
     * opened and guarded reads fail closed inside FormulaReadScope. Plain
     * formulas are unaffected either way.
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    private function inReadScope(string $tenantId, ?User $actor, callable $fn): mixed
    {
        if ($actor === null || $tenantId === '') {
            return $fn();
        }

        return FormulaReadScope::run($tenantId, $actor, $fn);
    }
}

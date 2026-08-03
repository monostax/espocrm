<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureAutomation\Services;

use Espo\Core\Exceptions\Error;
use Espo\Core\Utils\Metadata;
use Espo\Modules\FeatureJourney\Services\JourneyWhatsAppOutbound;
use Espo\Modules\FeatureJourney\Services\PeriodParser;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use stdClass;
use Throwable;

/**
 * Validates Automation.definition JSON.
 *
 * Batch: stages[] with scope forEach|once (map materializes sets; actions never nest under map steps).
 * Legacy flat {map, actions} expands to a single forEach stage.
 * Machine: states + transitions with waits.
 */
class AutomationDefinitionValidator
{
    /** @var list<string> */
    private const MAP_ENTITY_ALLOW = [
        'Tenant',
        'User',
        'Contact',
        'Account',
        'Lead',
        'Opportunity',
        'Task',
    ];

    /** @var list<string> */
    private const MAP_MODES = ['primary', 'expand', 'loop', 'passThrough', 'groupBy', 'report'];

    /** @var list<string> */
    private const MAP_SOURCES = ['query', 'relation', 'linkMultiple', 'ids', 'payload', 'report'];

    public function __construct(
        private Metadata $metadata,
        private PeriodParser $periodParser,
        private RunDataBag $runDataBag,
        private WakeAtResolver $wakeAtResolver,
        private EntityManager $entityManager,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function normalizeAndValidate(Entity $automation): array
    {
        $raw = $automation->get('definition');
        $def = $this->toArray($raw);
        $kind = (string) ($automation->get('kind') ?: 'Batch');

        if ($def === []) {
            throw new Error('Automation definition is required.');
        }

        $defKind = (string) ($def['kind'] ?? strtolower($kind));
        if (strtolower($defKind) === 'batch' || $kind === 'Batch') {
            return $this->validateBatch($def);
        }

        if (strtolower($defKind) === 'machine' || $kind === 'Machine') {
            return $this->validateMachine($def);
        }

        throw new Error("Unknown automation kind: {$kind}");
    }

    /**
     * @param array<string, mixed> $def
     * @return array<string, mixed>
     */
    private function validateBatch(array $def): array
    {
        $def['kind'] = 'batch';

        $limits = $this->toArray($def['limits'] ?? []);
        $def['limits'] = [
            'maxItems' => max(1, min(50000, (int) ($limits['maxItems'] ?? 5000))),
            'maxExpandPerParent' => max(1, min(5000, (int) ($limits['maxExpandPerParent'] ?? 500))),
        ];

        $rawStages = $this->expandLegacyBatchStages($def);
        if ($rawStages === []) {
            throw new Error('Batch definition requires stages[] (or legacy map[]).');
        }

        $stages = [];
        $stageIds = [];
        foreach ($rawStages as $i => $raw) {
            $raw = $this->toArray($raw);
            if ($raw === []) {
                throw new Error("Stage {$i} must be an object.");
            }

            $id = trim((string) ($raw['id'] ?? ('s' . $i)));
            if ($id === '') {
                $id = 's' . $i;
            }
            if (isset($stageIds[$id])) {
                throw new Error("Duplicate stage id '{$id}'.");
            }
            $stageIds[$id] = true;

            $scope = $this->normalizeStageScope($raw, $i);

            $itemMode = in_array(($raw['itemMode'] ?? 'allMatching'), ['allMatching', 'firstMatch'], true)
                ? (string) $raw['itemMode']
                : 'allMatching';

            $map = [];
            if ($scope === 'forEach') {
                $rawMap = $raw['map'] ?? null;
                if (!is_array($rawMap) || $rawMap === []) {
                    throw new Error("Stage '{$id}' (forEach) requires non-empty map[].");
                }
                $map = $this->validateMapSteps($rawMap, "stage '{$id}'");
            } elseif (isset($raw['map']) && is_array($raw['map']) && $raw['map'] !== []) {
                // once stages may carry map only if explicitly provided (ignored at runtime)
                $map = $this->validateMapSteps($raw['map'], "stage '{$id}'");
            }

            $stages[] = [
                'id' => $id,
                'scope' => $scope,
                'map' => $map,
                'actions' => $this->validateActions($raw['actions'] ?? []),
                'onFailure' => $this->validateActions($raw['onFailure'] ?? []),
                'itemMode' => $itemMode,
                'exportToRunBag' => $this->validateExportToRunBag(
                    $raw['exportToRunBag'] ?? null,
                    "stage '{$id}'"
                ),
                'importRunBag' => $this->validateImportRunBag(
                    $raw['importRunBag'] ?? null,
                    "stage '{$id}'"
                ),
            ];
        }

        $def['stages'] = $stages;

        // Backward-compatible top-level mirrors (first forEach, else first stage).
        $mirror = null;
        foreach ($stages as $st) {
            if (($st['scope'] ?? '') === 'forEach') {
                $mirror = $st;
                break;
            }
        }
        $mirror ??= $stages[0];

        $def['map'] = $mirror['map'] ?? [];
        $def['actions'] = $mirror['actions'] ?? [];
        $def['onFailure'] = $mirror['onFailure'] ?? [];
        $def['itemMode'] = $mirror['itemMode'] ?? 'allMatching';

        if (($mirror['scope'] ?? '') === 'forEach' && ($def['map'] ?? []) === []) {
            throw new Error('Batch definition requires at least one forEach stage with map[], or legacy map[].');
        }

        return $def;
    }

    /**
     * Legacy {map,actions} → single forEach stage; or pass-through stages[].
     *
     * @param array<string, mixed> $def
     * @return list<array<string, mixed>>
     */
    private function expandLegacyBatchStages(array $def): array
    {
        $stages = $def['stages'] ?? null;
        if (is_array($stages) && $stages !== []) {
            $out = [];
            foreach ($stages as $s) {
                $out[] = $this->toArray($s);
            }

            return $out;
        }

        $map = $def['map'] ?? null;
        if (!is_array($map) || $map === []) {
            return [];
        }

        return [[
            'id' => 's0',
            'scope' => 'forEach',
            'map' => $map,
            'actions' => $def['actions'] ?? [],
            'onFailure' => $def['onFailure'] ?? [],
            'itemMode' => $def['itemMode'] ?? 'allMatching',
        ]];
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function normalizeStageScope(array $raw, int $index): string
    {
        $scope = strtolower(trim((string) ($raw['scope'] ?? '')));
        if ($scope === '') {
            $type = strtolower(trim((string) ($raw['type'] ?? '')));
            $scope = match ($type) {
                'once', 'run', 'afterall', 'after_all', 'complete' => 'once',
                'foreach', 'for_each', 'map', 'items', 'batch' => 'forEach',
                default => '',
            };
        }

        if ($scope === 'foreach' || $scope === 'for_each') {
            $scope = 'forEach';
        }
        if (in_array($scope, ['run', 'afterall', 'after_all', 'complete'], true)) {
            $scope = 'once';
        }

        if (!in_array($scope, ['forEach', 'once'], true)) {
            // Infer: map present → forEach, else once
            $hasMap = isset($raw['map']) && is_array($raw['map']) && $raw['map'] !== [];
            $scope = $hasMap ? 'forEach' : 'once';
        }

        if (!in_array($scope, ['forEach', 'once'], true)) {
            throw new Error("Stage {$index}: scope must be forEach|once.");
        }

        return $scope;
    }

    /**
     * @param mixed $map
     * @return list<array<string, mixed>>
     */
    private function validateMapSteps(mixed $map, string $ctx): array
    {
        if (!is_array($map) || $map === []) {
            throw new Error("{$ctx}: non-empty map[] required.");
        }

        $ids = [];
        $out = [];

        foreach ($map as $i => $step) {
            $step = $this->toArray($step);
            if ($step === [] && !is_array($map[$i] ?? null)) {
                throw new Error("{$ctx} map step {$i} must be an object.");
            }

            $id = (string) ($step['id'] ?? "step{$i}");
            $entityType = (string) ($step['entityType'] ?? '');
            $mode = (string) ($step['mode'] ?? ($i === 0 ? 'primary' : 'expand'));
            if ($mode === 'loop') {
                $mode = 'expand';
            }

            $source = (string) ($step['source'] ?? '');

            if ($mode === 'report') {
                $source = $source !== '' ? $source : 'report';
                $mode = $i === 0 ? 'primary' : 'expand';
            }

            if ($source === '') {
                if (!empty($step['reportId'])) {
                    $source = 'report';
                } elseif (!empty($step['payloadPath'])) {
                    $source = 'payload';
                } elseif (!empty($step['ids']) || !empty($step['idsPath'])) {
                    $source = 'ids';
                } elseif (!empty($step['link']) || !empty($step['linkMultiple'])) {
                    $source = 'linkMultiple';
                } elseif (!empty($step['relation']) || ($i > 0 && $mode !== 'primary')) {
                    $source = 'relation';
                } else {
                    $source = 'query';
                }
            }

            if (!in_array($mode, ['primary', 'expand', 'passThrough', 'groupBy'], true)) {
                throw new Error("{$ctx} map step {$id}: invalid mode (primary|expand|loop|passThrough|groupBy).");
            }

            if (!in_array($source, self::MAP_SOURCES, true)) {
                throw new Error("{$ctx} map step {$id}: invalid source '{$source}'.");
            }

            if ($source !== 'payload' && $entityType === '') {
                throw new Error("{$ctx} map step {$id}: entityType is required for source={$source}.");
            }

            if ($entityType !== '' && !in_array($entityType, self::MAP_ENTITY_ALLOW, true)) {
                throw new Error("{$ctx} map step {$id}: entityType '{$entityType}' not allowed.");
            }

            if ($i === 0) {
                if (in_array($source, ['relation', 'linkMultiple'], true)) {
                    throw new Error("{$ctx} map step {$id}: first step cannot use source={$source} (no parent).");
                }
                $mode = 'primary';
            }

            $row = $step;
            $row['id'] = $id;
            $row['mode'] = $mode;
            $row['source'] = $source;

            if ($source === 'report') {
                if ((string) ($step['reportId'] ?? '') === '') {
                    throw new Error("{$ctx} map step {$id}: reportId required for source=report.");
                }
                $row['reportId'] = (string) $step['reportId'];
                if (isset($step['maxRows'])) {
                    $row['maxRows'] = max(1, min(10000, (int) $step['maxRows']));
                }
            }

            if ($source === 'payload' && empty($step['payloadPath'])) {
                throw new Error("{$ctx} map step {$id}: payloadPath required for source=payload.");
            }

            if ($source === 'ids' && empty($step['ids']) && empty($step['idsPath'])) {
                throw new Error("{$ctx} map step {$id}: ids or idsPath required for source=ids.");
            }

            if ($source === 'linkMultiple' && empty($step['link']) && empty($step['linkMultiple'])) {
                throw new Error("{$ctx} map step {$id}: link required for source=linkMultiple.");
            }

            if (
                $i > 0 &&
                $mode !== 'primary' &&
                in_array($source, ['relation', 'linkMultiple'], true) &&
                empty($step['parent'])
            ) {
                throw new Error("{$ctx} map step {$id}: parent is required for source={$source}.");
            }

            if (isset($ids[$id])) {
                throw new Error("{$ctx}: duplicate map step id '{$id}'.");
            }
            $ids[$id] = true;

            if ($entityType !== '') {
                $row['entityType'] = $entityType;
            }
            if (!empty($step['payloadPath'])) {
                $row['payloadPath'] = (string) $step['payloadPath'];
            }
            if (!empty($step['idsPath'])) {
                $row['idsPath'] = (string) $step['idsPath'];
            }
            if (isset($step['ids']) && is_array($step['ids'])) {
                $row['ids'] = array_values($step['ids']);
            }
            if (!empty($step['link'])) {
                $row['link'] = (string) $step['link'];
            } elseif (!empty($step['linkMultiple'])) {
                $row['link'] = (string) $step['linkMultiple'];
            }
            if (!empty($step['parent'])) {
                $row['parent'] = (string) $step['parent'];
            }
            if (!empty($step['relation'])) {
                $row['relation'] = (string) $step['relation'];
            }
            if (!empty($step['foreignKey'])) {
                $row['foreignKey'] = (string) $step['foreignKey'];
            }
            if (!empty($step['ignoreParent'])) {
                $row['ignoreParent'] = true;
            }

            // requireRoles: keep only Users holding one of these roles (direct or
            // via teams). Only meaningful for User steps — the materializer drops
            // non-User members rather than passing them through.
            $requireRoles = $this->normalizeStringList($step['requireRoles'] ?? null);
            if ($requireRoles !== []) {
                if ($entityType !== 'User') {
                    throw new Error(
                        "{$ctx} map step {$id}: requireRoles only applies to entityType User, got '{$entityType}'."
                    );
                }
                $row['requireRoles'] = $requireRoles;
            }

            if (isset($step['where'])) {
                $w = $this->toArray($step['where']);
                $row['where'] = $w;
            }
            if (isset($step['whereFormulas'])) {
                $wf = $this->toArray($step['whereFormulas']);
                $cleanWf = [];
                foreach ($wf as $field => $script) {
                    if (!is_string($field) || $field === '' || $field === 'deleted' || $field === 'tenantId') {
                        continue;
                    }
                    if (!is_string($script) || trim($script) === '') {
                        continue;
                    }
                    $cleanWf[$field] = trim($script);
                }
                if ($cleanWf !== []) {
                    $row['whereFormulas'] = $cleanWf;
                }
            }

            if ($mode === 'groupBy') {
                if (isset($step['groupBy'])) {
                    if (is_string($step['groupBy']) && $step['groupBy'] !== '') {
                        $row['groupBy'] = $step['groupBy'];
                    } elseif (is_array($step['groupBy'])) {
                        $row['groupBy'] = array_values(array_filter(
                            array_map('strval', $step['groupBy']),
                            fn($f) => $f !== ''
                        ));
                    }
                }
                if (!empty($step['groupTargetEntityType'])) {
                    $gt = (string) $step['groupTargetEntityType'];
                    if (!in_array($gt, self::MAP_ENTITY_ALLOW, true)) {
                        throw new Error("{$ctx} map step {$id}: groupTargetEntityType '{$gt}' not allowed.");
                    }
                    $row['groupTargetEntityType'] = $gt;
                }
                if (isset($step['timeBucket'])) {
                    $tb = $this->toArray($step['timeBucket']);
                    $size = trim((string) ($tb['size'] ?? '1 hour'));
                    if ($size !== '') {
                        try {
                            $this->periodParser->parse($size);
                        } catch (Throwable $e) {
                            throw new Error("{$ctx} map step {$id}: invalid timeBucket.size — " . $e->getMessage());
                        }
                    }
                    $row['timeBucket'] = [
                        'field' => (string) ($tb['field'] ?? 'createdAt'),
                        'size' => $size !== '' ? $size : '1 hour',
                        'timezone' => (string) ($tb['timezone'] ?? 'UTC'),
                    ];
                }
                if (isset($step['aggregates']) && is_array($step['aggregates'])) {
                    $aggs = [];
                    foreach ($step['aggregates'] as $agg) {
                        $agg = $this->toArray($agg);
                        $op = strtolower((string) ($agg['op'] ?? 'count'));
                        if (!in_array($op, ['count', 'sum', 'min', 'max', 'avg', 'average', 'collectIds', 'collect_ids', 'ids'], true)) {
                            throw new Error("{$ctx} map step {$id}: invalid aggregate op '{$op}'.");
                        }
                        $aggRow = ['op' => $op, 'as' => (string) ($agg['as'] ?? $op)];
                        if (!empty($agg['field'])) {
                            $aggRow['field'] = (string) $agg['field'];
                        }
                        $aggs[] = $aggRow;
                    }
                    $row['aggregates'] = $aggs;
                }
            }

            $out[] = $row;
        }

        return array_values($out);
    }

    private function validateMachine(array $def): array
    {
        $def['kind'] = 'machine';
        $states = $def['states'] ?? null;

        if (!is_array($states) || $states === []) {
            throw new Error('Machine definition requires states[].');
        }

        $normalized = [];
        $ids = [];

        foreach ($states as $i => $state) {
            if ($state instanceof stdClass) {
                $state = (array) $state;
            }

            if (!is_array($state)) {
                throw new Error("State {$i} must be an object.");
            }

            $id = (string) ($state['id'] ?? '');
            if ($id === '') {
                throw new Error("State {$i} missing id.");
            }

            if (isset($ids[$id])) {
                throw new Error("Duplicate state id '{$id}'.");
            }

            $ids[$id] = true;
            $type = (string) ($state['type'] ?? 'normal');
            if (!in_array($type, ['normal', 'wait', 'join', 'final'], true)) {
                throw new Error("State {$id}: type must be normal|wait|join|final.");
            }

            if ($type === 'wait') {
                $state = $this->normalizeWaitSpec($state, "Wait state {$id}");
            }

            if ($type === 'join') {
                $joinMode = (string) ($state['joinMode'] ?? 'waitAll');
                if (!in_array($joinMode, ['waitAll', 'waitAny'], true)) {
                    throw new Error("Join state {$id}: joinMode must be waitAll|waitAny.");
                }
                $state['joinMode'] = $joinMode;
                $timeout = trim((string) ($state['timeoutPeriod'] ?? $state['waitPeriod'] ?? '2 hours'));
                try {
                    $this->periodParser->parse($timeout);
                } catch (Throwable $e) {
                    throw new Error("Join state {$id}: invalid timeoutPeriod — " . $e->getMessage());
                }
                $state['timeoutPeriod'] = $timeout;
            }

            $state['type'] = $type;
            $state['onEnter'] = $this->validateActions($state['onEnter'] ?? []);
            $state['onExit'] = $this->validateActions($state['onExit'] ?? []);
            $normalized[] = $state;
        }

        $def['states'] = $normalized;
        $def['initial'] = (string) ($def['initial'] ?? $normalized[0]['id']);

        if (!isset($ids[$def['initial']])) {
            throw new Error('Machine initial state not found.');
        }

        $transitions = [];
        foreach (($def['transitions'] ?? []) as $t) {
            if ($t instanceof stdClass) {
                $t = (array) $t;
            }

            if (!is_array($t)) {
                continue;
            }

            $from = (string) ($t['from'] ?? '');
            $to = (string) ($t['to'] ?? '');

            if ($from === '' || $to === '' || !isset($ids[$from]) || !isset($ids[$to])) {
                throw new Error('Invalid machine transition.');
            }

            $t = $this->normalizeWaitSpec($t, 'Transition', false);

            if (isset($t['when']) && !is_string($t['when'])) {
                unset($t['when']);
            }

            $transitions[] = $t;
        }

        $def['transitions'] = $transitions;

        return $def;
    }

    /**
     * @param mixed $actions
     * @return list<array<string, mixed>>
     */
    private function validateActions(mixed $actions): array
    {
        if ($actions instanceof stdClass) {
            $actions = (array) $actions;
        }

        if (!is_array($actions)) {
            return [];
        }

        $out = [];
        $known = $this->metadata->get(['app', 'automationActionTypes', 'typeList']) ?? [];
        if (!is_array($known)) {
            $known = [];
        }

        foreach ($actions as $i => $action) {
            if ($action instanceof stdClass) {
                $action = (array) $action;
            }

            if (!is_array($action)) {
                throw new Error("Action {$i} must be an object.");
            }

            $type = (string) ($action['type'] ?? '');
            if ($type === '' || ($known !== [] && !in_array($type, $known, true))) {
                throw new Error("Action type '{$type}' is not allowed.");
            }

            $params = $this->toArray($action['params'] ?? []);
            unset($params['tenantId']);

            $paramFormulas = $this->toArray($action['paramFormulas'] ?? []);
            unset($paramFormulas['tenantId']);

            if ($type === 'startChildAutomation') {
                if (empty($params['automationId']) || !is_string($params['automationId'])) {
                    throw new Error("Action {$i} startChildAutomation requires params.automationId.");
                }
            }

            if ($type === 'waitJoin') {
                $mode = (string) ($params['mode'] ?? 'waitAll');
                if (!in_array($mode, ['waitAll', 'waitAny'], true)) {
                    throw new Error("Action {$i} waitJoin: params.mode must be waitAll|waitAny.");
                }
                $params['mode'] = $mode;
                $timeout = trim((string) ($params['timeoutPeriod'] ?? '2 hours'));
                if ($timeout !== '') {
                    try {
                        $this->periodParser->parse($timeout);
                    } catch (Throwable $e) {
                        throw new Error("Action {$i} waitJoin: invalid timeoutPeriod — " . $e->getMessage());
                    }
                    $params['timeoutPeriod'] = $timeout;
                }
            }

            if ($type === 'waitUntil') {
                $at = trim((string) ($params['at'] ?? ''));
                $hasFormula = isset($paramFormulas['at']) &&
                    is_string($paramFormulas['at']) &&
                    trim($paramFormulas['at']) !== '';
                if ($at === '' && !$hasFormula) {
                    throw new Error("Action {$i} waitUntil: params.at (or at formula) required.");
                }
                $tz = trim((string) ($params['timezone'] ?? ''));
                if ($at !== '') {
                    if (!$this->wakeAtResolver->isValidFixed($at, $tz !== '' ? $tz : null)) {
                        throw new Error(
                            "Action {$i} waitUntil: invalid params.at — use datetime " .
                            "(e.g. 2026-08-01 14:00:00 or ISO-8601)."
                        );
                    }
                    $params['at'] = $at;
                }
                if ($tz !== '') {
                    try {
                        new \DateTimeZone($tz);
                    } catch (Throwable $e) {
                        throw new Error("Action {$i} waitUntil: invalid params.timezone — " . $e->getMessage());
                    }
                    $params['timezone'] = $tz;
                } else {
                    unset($params['timezone']);
                }
            }

            if ($type === 'runReport') {
                $reportId = trim((string) ($params['reportId'] ?? ''));
                if ($reportId === '') {
                    throw new Error("Action {$i} runReport requires params.reportId.");
                }
                $params['reportId'] = $reportId;
                $as = trim((string) ($params['as'] ?? 'report'));
                if ($as === '') {
                    $as = 'report';
                }
                if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)*$/', $as)) {
                    throw new Error("Action {$i} runReport: invalid params.as path.");
                }
                if (str_starts_with(explode('.', $as)[0], '_')) {
                    throw new Error("Action {$i} runReport: params.as cannot use reserved payload roots.");
                }
                $params['as'] = $as;
                $mode = strtolower(trim((string) ($params['mode'] ?? 'auto')));
                if (!in_array($mode, ['auto', 'list', 'grid'], true)) {
                    throw new Error("Action {$i} runReport: params.mode must be auto|list|grid.");
                }
                $params['mode'] = $mode;
                $period = trim((string) ($params['period'] ?? 'none'));
                $allowedPeriods = [
                    'none', 'currentDay', 'previousDay', 'currentWeek', 'previousWeek',
                    'currentMonth', 'previousMonth', 'today', 'yesterday', 'thisWeek',
                    'lastWeek', 'thisMonth', 'lastMonth',
                ];
                if ($period !== '' && !in_array($period, $allowedPeriods, true)) {
                    throw new Error("Action {$i} runReport: invalid params.period.");
                }
                if ($period !== '' && $period !== 'none') {
                    $params['period'] = $period;
                    $pf = trim((string) ($params['periodField'] ?? ''));
                    // period without field still stored (report may have own filters)
                    if ($pf !== '') {
                        $params['periodField'] = $pf;
                    }
                } else {
                    $params['period'] = 'none';
                }
                if (isset($params['maxRows'])) {
                    $params['maxRows'] = max(1, min(2000, (int) $params['maxRows']));
                }
                if (array_key_exists('scopeAiAgentConversations', $params)) {
                    $v = $params['scopeAiAgentConversations'];
                    $params['scopeAiAgentConversations'] = $v === true
                        || $v === 1
                        || $v === '1'
                        || (is_string($v) && in_array(strtolower(trim($v)), ['true', 'yes', 'on'], true));
                }
                unset($params['tenantScoped']);
            }

            if ($type === 'setPayload' || $type === 'assign') {
                $hasPath = trim((string) ($params['path'] ?? '')) !== '';
                $hasValue = array_key_exists('value', $params) ||
                    (isset($paramFormulas['value']) && is_string($paramFormulas['value']) && trim($paramFormulas['value']) !== '');
                $assignments = $params['assignments'] ?? null;
                $hasAssignments = is_array($assignments) && $assignments !== [];
                if ((!$hasPath || !$hasValue) && !$hasAssignments) {
                    throw new Error(
                        "Action {$i} {$type}: require path+value (or value formula) or assignments[]."
                    );
                }
                if ($hasPath) {
                    $p = trim((string) $params['path']);
                    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)*$/', $p)) {
                        throw new Error("Action {$i} {$type}: invalid params.path.");
                    }
                    if (str_starts_with(explode('.', $p)[0], '_')) {
                        throw new Error("Action {$i} {$type}: cannot write reserved payload root.");
                    }
                    $params['path'] = $p;
                }
            }

            if ($type === 'exportToRunData') {
                $mode = strtolower(trim((string) ($params['mode'] ?? 'merge')));
                if (!in_array($mode, ['merge', 'replace'], true)) {
                    throw new Error("Action {$i} exportToRunData: params.mode must be merge|replace.");
                }
                $params['mode'] = $mode;
                if (isset($params['path']) && is_string($params['path']) && trim($params['path']) !== '') {
                    $p = trim($params['path']);
                    if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', explode('.', $p)[0])) {
                        throw new Error("Action {$i} exportToRunData: invalid params.path.");
                    }
                    if (str_starts_with(explode('.', $p)[0], '_')) {
                        throw new Error("Action {$i} exportToRunData: cannot export reserved payload root.");
                    }
                    $params['path'] = $p;
                }
                if (array_key_exists('keys', $params) && $params['keys'] !== null && $params['keys'] !== '') {
                    try {
                        $cfg = $this->runDataBag->normalizeExportConfig(
                            is_string($params['keys']) || is_array($params['keys']) || is_bool($params['keys'])
                                ? $params['keys']
                                : true
                        );
                        if ($cfg === null) {
                            throw new Error('empty keys');
                        }
                        $params['keys'] = $cfg['keys'] === true ? true : $cfg['keys'];
                    } catch (Error $e) {
                        throw new Error("Action {$i} exportToRunData: invalid keys — " . $e->getMessage());
                    }
                }
            }

            if ($type === 'sendWhatsAppMessage') {
                $inboxId = trim((string) ($params['chatwootInboxId'] ?? ''));
                $inboxFormula = $this->paramFormulaScript($paramFormulas, 'chatwootInboxId');
                $body = trim((string) ($params['body'] ?? ''));
                $bodyFormula = $this->paramFormulaScript($paramFormulas, 'body');

                if ($inboxId === '' && $inboxFormula === '') {
                    throw new Error(
                        "Action {$i} sendWhatsAppMessage requires chatwootInboxId (WhatsApp Inbox)."
                    );
                }

                if ($body === '' && $bodyFormula === '') {
                    throw new Error(
                        "Action {$i} sendWhatsAppMessage requires body (static text or dynamic/fx formula)."
                    );
                }

                if ($inboxId !== '') {
                    $this->assertWhatsAppInboxChannel(
                        $inboxId,
                        JourneyWhatsAppOutbound::CHANNELS_MESSAGE,
                        "Action {$i} sendWhatsAppMessage"
                    );
                }
            }

            if ($type === 'sendWhatsAppTemplate') {
                $inboxId = trim((string) ($params['chatwootInboxId'] ?? ''));
                $inboxFormula = $this->paramFormulaScript($paramFormulas, 'chatwootInboxId');
                $templateName = trim((string) ($params['templateName'] ?? ''));

                if ($inboxId === '' && $inboxFormula === '') {
                    throw new Error(
                        "Action {$i} sendWhatsAppTemplate requires chatwootInboxId (Cloud/Coexistence inbox)."
                    );
                }

                if ($templateName === '') {
                    throw new Error("Action {$i} sendWhatsAppTemplate requires templateName.");
                }

                if ($inboxId !== '') {
                    $this->assertWhatsAppInboxChannel(
                        $inboxId,
                        JourneyWhatsAppOutbound::CHANNELS_TEMPLATE,
                        "Action {$i} sendWhatsAppTemplate"
                    );
                }
            }

            $enabled = true;
            if (array_key_exists('enabled', $action)) {
                $enabled = (bool) $action['enabled'];
            } elseif (array_key_exists('isActive', $action)) {
                $enabled = (bool) $action['isActive'];
            } elseif (array_key_exists('paused', $action) && $action['paused']) {
                $enabled = false;
            } elseif (array_key_exists('disabled', $action) && $action['disabled']) {
                $enabled = false;
            }

            $row = [
                'type' => $type,
                'enabled' => $enabled,
                'when' => isset($action['when']) && is_string($action['when']) ? $action['when'] : null,
                'params' => $params,
                'paramFormulas' => $paramFormulas,
                'continueOnError' => (bool) ($action['continueOnError'] ?? false),
                'maxRetries' => max(0, min(5, (int) ($action['maxRetries'] ?? 0))),
            ];

            if (array_key_exists('idempotencyKey', $action)) {
                $ik = $action['idempotencyKey'];
                if ($ik === true || $ik === false) {
                    $row['idempotencyKey'] = $ik;
                } elseif (is_string($ik) && trim($ik) !== '') {
                    $row['idempotencyKey'] = trim($ik);
                }
            }

            if (isset($action['debounce']) && is_string($action['debounce']) && trim($action['debounce']) !== '') {
                $deb = trim($action['debounce']);
                try {
                    $this->periodParser->parse($deb);
                } catch (Throwable $e) {
                    throw new Error("Action {$i}: invalid debounce — " . $e->getMessage());
                }
                $row['debounce'] = $deb;
            }

            $out[] = $row;
        }

        return $out;
    }

    /**
     * @param list<string> $allowed
     */
    private function assertWhatsAppInboxChannel(string $inboxId, array $allowed, string $label): void
    {
        $inbox = $this->entityManager->getEntityById('ChatwootInbox', $inboxId);
        if (!$inbox) {
            throw new Error("{$label}: Chatwoot inbox not found.");
        }

        $channelType = (string) ($inbox->get('channelType') ?? '');
        if ($channelType === '' || !in_array($channelType, $allowed, true)) {
            throw new Error(
                "{$label}: inbox channelType '{$channelType}' is not allowed " .
                '(expected: ' . implode(', ', $allowed) . ').'
            );
        }
    }

    /**
     * Non-empty formula script from action paramFormulas for a field key.
     *
     * @param array<string, mixed> $paramFormulas
     */
    private function paramFormulaScript(array $paramFormulas, string $key): string
    {
        if (!isset($paramFormulas[$key]) || !is_string($paramFormulas[$key])) {
            return '';
        }

        return trim($paramFormulas[$key]);
    }

    /**
     * Normalise waitPeriod / waitUntil / waitUntilFormula on a state or transition.
     * When $required, exactly one of period or until(+formula) must be set.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeWaitSpec(array $row, string $ctx, bool $required = true): array
    {
        $period = trim((string) ($row['waitPeriod'] ?? ''));
        $until = trim((string) ($row['waitUntil'] ?? ''));
        $untilFormula = trim((string) ($row['waitUntilFormula'] ?? ''));
        $tz = trim((string) ($row['waitUntilTimezone'] ?? ''));

        $hasPeriod = $period !== '';
        $hasUntil = $until !== '' || $untilFormula !== '';

        if ($hasPeriod && $hasUntil) {
            throw new Error("{$ctx}: use waitPeriod or waitUntil, not both.");
        }

        if ($required && !$hasPeriod && !$hasUntil) {
            throw new Error(
                "{$ctx}: waitPeriod (e.g. '1 day') or waitUntil (datetime) required."
            );
        }

        if ($hasPeriod) {
            try {
                $this->periodParser->parse($period);
            } catch (Throwable $e) {
                throw new Error("{$ctx}: invalid waitPeriod — " . $e->getMessage());
            }
            $row['waitPeriod'] = $period;
            unset($row['waitUntil'], $row['waitUntilFormula'], $row['waitUntilTimezone']);

            return $row;
        }

        unset($row['waitPeriod']);

        if (!$hasUntil) {
            unset($row['waitUntil'], $row['waitUntilFormula'], $row['waitUntilTimezone']);

            return $row;
        }

        if ($tz !== '') {
            try {
                new \DateTimeZone($tz);
            } catch (Throwable $e) {
                throw new Error("{$ctx}: invalid waitUntilTimezone — " . $e->getMessage());
            }
            $row['waitUntilTimezone'] = $tz;
        } else {
            unset($row['waitUntilTimezone']);
            $tz = '';
        }

        if ($untilFormula !== '') {
            $row['waitUntilFormula'] = $untilFormula;
        } else {
            unset($row['waitUntilFormula']);
        }

        if ($until !== '') {
            if (!$this->wakeAtResolver->isValidFixed($until, $tz !== '' ? $tz : null)) {
                throw new Error(
                    "{$ctx}: invalid waitUntil — use datetime " .
                    "(e.g. 2026-08-01 14:00:00 or ISO-8601)."
                );
            }
            $row['waitUntil'] = $until;
        } else {
            unset($row['waitUntil']);
        }

        return $row;
    }

    /**
     * @return array{keys: list<string>|true, from: string, mode: string}|false
     */
    private function validateExportToRunBag(mixed $raw, string $ctx): array|false
    {
        if ($raw === null || $raw === false || $raw === '' || $raw === 0 || $raw === '0') {
            return false;
        }

        try {
            $cfg = $this->runDataBag->normalizeExportConfig($raw);
        } catch (Error $e) {
            throw new Error("{$ctx} exportToRunBag: " . $e->getMessage());
        }

        if ($cfg === null) {
            return false;
        }

        return $cfg;
    }

    /**
     * @return array{keys: list<string>|true, into: string, overwrite: bool}|false
     */
    private function validateImportRunBag(mixed $raw, string $ctx): array|false
    {
        if ($raw === null || $raw === false || $raw === '' || $raw === 0 || $raw === '0') {
            return false;
        }

        try {
            $cfg = $this->runDataBag->normalizeImportConfig($raw);
        } catch (Error $e) {
            throw new Error("{$ctx} importRunBag: " . $e->getMessage());
        }

        if ($cfg === null) {
            return false;
        }

        return $cfg;
    }

    /**
     * Accepts a string or list of strings; trims, drops empties, dedupes.
     *
     * @return list<string>
     */
    private function normalizeStringList(mixed $value): array
    {
        if ($value instanceof stdClass) {
            $value = (array) $value;
        }
        if (is_string($value)) {
            $value = [$value];
        }
        if (!is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (!is_string($item)) {
                continue;
            }
            $item = trim($item);
            if ($item !== '' && !in_array($item, $out, true)) {
                $out[] = $item;
            }
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function toArray(mixed $value): array
    {
        if ($value instanceof stdClass) {
            return json_decode(json_encode($value) ?: '{}', true) ?: [];
        }

        if (is_array($value)) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }
}

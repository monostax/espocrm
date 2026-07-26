<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureAutomation\Services;

use Espo\Core\Exceptions\Error;
use Espo\Core\Utils\Metadata;
use Espo\Modules\FeatureJourney\Services\PeriodParser;
use Espo\ORM\Entity;
use stdClass;
use Throwable;

/**
 * Validates Automation.definition JSON (Batch map + actions; Machine with waits).
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
        $map = $def['map'] ?? null;

        if (!is_array($map) || $map === []) {
            throw new Error('Batch definition requires non-empty map[].');
        }

        $ids = [];
        foreach ($map as $i => $step) {
            if ($step instanceof stdClass) {
                $step = (array) $step;
                $map[$i] = $step;
            }

            if (!is_array($step)) {
                throw new Error("Map step {$i} must be an object.");
            }

            $id = (string) ($step['id'] ?? "step{$i}");
            $entityType = (string) ($step['entityType'] ?? '');
            $mode = (string) ($step['mode'] ?? ($i === 0 ? 'primary' : 'expand'));
            if ($mode === 'loop') {
                $mode = 'expand';
            }

            $source = (string) ($step['source'] ?? '');

            // Legacy mode=report → source=report
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
                throw new Error("Map step {$id}: invalid mode (primary|expand|loop|passThrough|groupBy).");
            }

            if (!in_array($source, self::MAP_SOURCES, true)) {
                throw new Error("Map step {$id}: invalid source '{$source}'.");
            }

            if ($source !== 'payload' && $entityType === '') {
                throw new Error("Map step {$id}: entityType is required for source={$source}.");
            }

            if ($entityType !== '' && !in_array($entityType, self::MAP_ENTITY_ALLOW, true)) {
                throw new Error("Map step {$id}: entityType '{$entityType}' not allowed.");
            }

            if ($i === 0) {
                if (in_array($source, ['relation', 'linkMultiple'], true)) {
                    throw new Error("Map step {$id}: first step cannot use source={$source} (no parent).");
                }
                $mode = 'primary';
            }

            if ($source === 'report') {
                if ((string) ($step['reportId'] ?? '') === '') {
                    throw new Error("Map step {$id}: reportId required for source=report.");
                }
                $map[$i]['reportId'] = (string) $step['reportId'];
                if (isset($step['maxRows'])) {
                    $map[$i]['maxRows'] = max(1, min(10000, (int) $step['maxRows']));
                }
            }

            if ($source === 'payload' && empty($step['payloadPath'])) {
                throw new Error("Map step {$id}: payloadPath required for source=payload.");
            }

            if ($source === 'ids' && empty($step['ids']) && empty($step['idsPath'])) {
                throw new Error("Map step {$id}: ids or idsPath required for source=ids.");
            }

            if ($source === 'linkMultiple' && empty($step['link']) && empty($step['linkMultiple'])) {
                throw new Error("Map step {$id}: link required for source=linkMultiple.");
            }

            if (
                $i > 0 &&
                $mode !== 'primary' &&
                in_array($source, ['relation', 'linkMultiple'], true) &&
                empty($step['parent'])
            ) {
                throw new Error("Map step {$id}: parent is required for source={$source}.");
            }

            if (isset($ids[$id])) {
                throw new Error("Duplicate map step id '{$id}'.");
            }

            $ids[$id] = true;
            $map[$i]['id'] = $id;
            $map[$i]['mode'] = $mode;
            $map[$i]['source'] = $source;
            if ($entityType !== '') {
                $map[$i]['entityType'] = $entityType;
            }
            if (!empty($step['payloadPath'])) {
                $map[$i]['payloadPath'] = (string) $step['payloadPath'];
            }
            if (!empty($step['idsPath'])) {
                $map[$i]['idsPath'] = (string) $step['idsPath'];
            }
            if (isset($step['ids']) && is_array($step['ids'])) {
                $map[$i]['ids'] = array_values($step['ids']);
            }
            if (!empty($step['link'])) {
                $map[$i]['link'] = (string) $step['link'];
            } elseif (!empty($step['linkMultiple'])) {
                $map[$i]['link'] = (string) $step['linkMultiple'];
            }
            if (!empty($step['parent'])) {
                $map[$i]['parent'] = (string) $step['parent'];
            }
            if (!empty($step['relation'])) {
                $map[$i]['relation'] = (string) $step['relation'];
            }
            if (!empty($step['foreignKey'])) {
                $map[$i]['foreignKey'] = (string) $step['foreignKey'];
            }
            if (isset($step['where'])) {
                $w = $step['where'];
                if ($w instanceof stdClass) {
                    $w = json_decode(json_encode($w) ?: '{}', true) ?: [];
                }
                if (is_array($w)) {
                    $map[$i]['where'] = $w;
                }
            }

            if ($mode === 'groupBy') {
                if (isset($step['groupBy'])) {
                    if (is_string($step['groupBy']) && $step['groupBy'] !== '') {
                        $map[$i]['groupBy'] = $step['groupBy'];
                    } elseif (is_array($step['groupBy'])) {
                        $map[$i]['groupBy'] = array_values(array_filter(
                            array_map('strval', $step['groupBy']),
                            fn($f) => $f !== ''
                        ));
                    }
                }
                if (!empty($step['groupTargetEntityType'])) {
                    $gt = (string) $step['groupTargetEntityType'];
                    if (!in_array($gt, self::MAP_ENTITY_ALLOW, true)) {
                        throw new Error("Map step {$id}: groupTargetEntityType '{$gt}' not allowed.");
                    }
                    $map[$i]['groupTargetEntityType'] = $gt;
                }
                if (isset($step['timeBucket'])) {
                    $tb = $this->toArray($step['timeBucket']);
                    $size = trim((string) ($tb['size'] ?? '1 hour'));
                    if ($size !== '') {
                        try {
                            $this->periodParser->parse($size);
                        } catch (Throwable $e) {
                            throw new Error("Map step {$id}: invalid timeBucket.size — " . $e->getMessage());
                        }
                    }
                    $map[$i]['timeBucket'] = [
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
                            throw new Error("Map step {$id}: invalid aggregate op '{$op}'.");
                        }
                        $row = ['op' => $op, 'as' => (string) ($agg['as'] ?? $op)];
                        if (!empty($agg['field'])) {
                            $row['field'] = (string) $agg['field'];
                        }
                        $aggs[] = $row;
                    }
                    $map[$i]['aggregates'] = $aggs;
                }
            }
        }

        $def['map'] = array_values($map);
        $def['actions'] = $this->validateActions($def['actions'] ?? []);
        $def['onFailure'] = $this->validateActions($def['onFailure'] ?? []);
        $def['itemMode'] = in_array(($def['itemMode'] ?? 'allMatching'), ['allMatching', 'firstMatch'], true)
            ? $def['itemMode']
            : 'allMatching';

        $limits = $this->toArray($def['limits'] ?? []);
        $def['limits'] = [
            'maxItems' => max(1, min(50000, (int) ($limits['maxItems'] ?? 5000))),
            'maxExpandPerParent' => max(1, min(5000, (int) ($limits['maxExpandPerParent'] ?? 500))),
        ];

        return $def;
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
                $period = trim((string) ($state['waitPeriod'] ?? ''));
                if ($period === '') {
                    throw new Error("Wait state {$id}: waitPeriod required (e.g. '1 day').");
                }
                try {
                    $this->periodParser->parse($period);
                } catch (Throwable $e) {
                    throw new Error("State {$id}: invalid waitPeriod — " . $e->getMessage());
                }
                $state['waitPeriod'] = $period;
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

            $waitPeriod = trim((string) ($t['waitPeriod'] ?? ''));
            if ($waitPeriod !== '') {
                try {
                    $this->periodParser->parse($waitPeriod);
                } catch (Throwable $e) {
                    throw new Error('Transition waitPeriod invalid: ' . $e->getMessage());
                }
                $t['waitPeriod'] = $waitPeriod;
            } else {
                unset($t['waitPeriod']);
            }

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

            $row = [
                'type' => $type,
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

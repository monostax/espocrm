<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureJourney\Services;

use Espo\Core\InjectableFactory;
use Espo\Core\Select\SelectBuilderFactory;
use Espo\Core\Select\Where\Item as WhereItem;
use Espo\Core\Utils\Log;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Throwable;

/**
 * Pure-ish (record, signal, transition) -> bool evaluator.
 */
class TransitionEvaluator
{
    public function __construct(
        private EntityManager $entityManager,
        private SelectBuilderFactory $selectBuilderFactory,
        private RestrictedFormulaRunner $formulaRunner,
        private InjectableFactory $injectableFactory,
        private TenantGuard $tenantGuard,
        private CustomFieldsBag $customFieldsBag,
        private Log $log,
    ) {}

    /**
     * @param array{code?: string, payload?: array<string, mixed>, eventId?: string}|null $signal
     */
    public function evaluate(Entity $record, Entity $transition, ?array $signal = null): bool
    {
        $evaluatorClass = $transition->get('evaluatorClassName');

        if (is_string($evaluatorClass) && $evaluatorClass !== '') {
            if (!class_exists($evaluatorClass)) {
                $this->log->warning('TransitionEvaluator: evaluator class not found: ' . $evaluatorClass);

                return false;
            }

            try {
                $this->tenantGuard->assertClassAllowed($evaluatorClass, 'evaluatorClassNameList');
                $evaluator = $this->injectableFactory->create($evaluatorClass);

                if (is_callable($evaluator) || method_exists($evaluator, 'evaluate')) {
                    return (bool) (is_callable($evaluator)
                        ? $evaluator($record, $transition, $signal)
                        : $evaluator->evaluate($record, $transition, $signal));
                }

                $this->log->error(
                    'TransitionEvaluator: evaluator must be callable or expose evaluate: ' . $evaluatorClass
                );

                return false;
            } catch (Throwable $e) {
                $this->log->error('TransitionEvaluator: evaluatorClassName failed: ' . $e->getMessage());

                return false;
            }
        }

        $formula = $transition->get('conditionsFormula');

        if (is_string($formula) && trim($formula) !== '') {
            return $this->evaluateFormula($record, $formula, $signal);
        }

        $group = $transition->get('conditionsGroup');

        if ($group === null || $group === '' || $group === [] || $group === new \stdClass()) {
            return true;
        }

        if ($group instanceof \stdClass) {
            $group = json_decode(json_encode($group) ?: '{}', true);
        }

        if (!is_array($group) || $group === []) {
            return true;
        }

        return $this->evaluateNode($group, $record, $signal);
    }

    /**
     * @param array{code?: string, payload?: array<string, mixed>, eventId?: string}|null $signal
     */
    private function evaluateFormula(Entity $record, string $formula, ?array $signal): bool
    {
        try {
            $target = $this->loadTarget($record);
            $vars = (object) [
                'signalCode' => $signal['code'] ?? null,
                'signalPayload' => $signal['payload'] ?? null,
                'journeyRecordId' => $record->getId(),
            ];

            $result = $this->formulaRunner->run(
                $formula,
                $target,
                $vars,
                RestrictedFormulaRunner::MODE_CONDITION,
            );

            return (bool) $result;
        } catch (Throwable $e) {
            $this->log->warning('TransitionEvaluator: formula failed: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * @param array<string, mixed> $node
     * @param array{code?: string, payload?: array<string, mixed>, eventId?: string}|null $signal
     */
    private function evaluateNode(array $node, Entity $record, ?array $signal): bool
    {
        if (isset($node['and']) && is_array($node['and'])) {
            foreach ($node['and'] as $child) {
                if (!is_array($child) || !$this->evaluateNode($child, $record, $signal)) {
                    return false;
                }
            }

            return true;
        }

        if (isset($node['or']) && is_array($node['or'])) {
            foreach ($node['or'] as $child) {
                if (is_array($child) && $this->evaluateNode($child, $record, $signal)) {
                    return true;
                }
            }

            return false;
        }

        if (isset($node['not']) && is_array($node['not'])) {
            return !$this->evaluateNode($node['not'], $record, $signal);
        }

        $type = $node['type'] ?? null;

        return match ($type) {
            'payloadPath' => $this->evalPayloadPath($node, $signal),
            'entityFilter' => $this->evalEntityFilter($node, $record),
            'eventHistory' => $this->evalEventHistory($node, $record),
            'elapsedInStage' => $this->evalElapsedInStage($node, $record),
            'currentSignal' => $this->evalCurrentSignal($node, $signal),
            'anySignal' => $this->evalCurrentSignal($node, $signal),
            default => $this->evalLegacyLeaf($node, $record, $signal),
        };
    }

    /**
     * True when record has been in the current stage at least `period`.
     *
     * @param array<string, mixed> $node
     */
    private function evalElapsedInStage(array $node, Entity $record): bool
    {
        $period = (string) ($node['period'] ?? $node['waitPeriod'] ?? '');
        $entered = $record->get('enteredStageAt');

        if ($period === '' || !$entered) {
            return false;
        }

        try {
            return (new PeriodParser())->isDue((string) $entered, $period);
        } catch (Throwable $e) {
            $this->log->warning('TransitionEvaluator: elapsedInStage failed: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * Match the wake signal's code (this event only). Use eventHistory for cross-event AND.
     *
     * @param array<string, mixed> $node
     * @param array{code?: string, payload?: array<string, mixed>}|null $signal
     */
    private function evalCurrentSignal(array $node, ?array $signal): bool
    {
        $actual = $signal['code'] ?? null;

        if (!is_string($actual) || $actual === '') {
            return false;
        }

        $code = $node['code'] ?? null;
        $codes = $node['codes'] ?? null;

        if (is_string($code) && $code !== '') {
            return $actual === $code;
        }

        if (is_array($codes)) {
            return in_array($actual, $codes, true);
        }

        return false;
    }

    /**
     * @param array<string, mixed> $node
     * @param array{code?: string, payload?: array<string, mixed>}|null $signal
     */
    private function evalPayloadPath(array $node, ?array $signal): bool
    {
        $path = (string) ($node['path'] ?? '');
        $op = (string) ($node['operator'] ?? 'equals');
        $expected = $node['value'] ?? null;

        $payload = $signal['payload'] ?? [];
        if (!is_array($payload)) {
            $payload = [];
        }

        $actual = $this->pathGet($payload, preg_replace('/^event\.payload\.?/', '', $path) ?? $path);

        return $this->compare($actual, $op, $expected);
    }

    /**
     * Entity filter supports standard Espo where items on host attributes +
     * Monostax CustomField bag leaves as `customFields.<valueKey>`
     * (valueKey may itself contain dots, e.g. customFields.billing.plan).
     *
     * Bag clauses are evaluated in-memory on the loaded target; native clauses
     * use SelectBuilder (links / full where syntax). Mixed lists are AND-ed.
     *
     * @param array<string, mixed> $node
     */
    private function evalEntityFilter(array $node, Entity $record): bool
    {
        $where = $node['where'] ?? null;
        $targetType = $record->get('targetType');
        $targetId = $record->get('targetId');
        $tenantId = $record->get('tenantId');

        if (!$where || !$targetType || !$targetId) {
            return false;
        }

        if ($where instanceof \stdClass) {
            $where = json_decode(json_encode($where) ?: '[]', true);
        }

        if (!is_array($where)) {
            return false;
        }

        $items = $this->customFieldsBag->normalizeWhereList($where);

        if ($items === []) {
            return false;
        }

        try {
            $partition = $this->customFieldsBag->partitionWhere($items);

            if ($partition['bag'] !== []) {
                $target = $this->loadTarget($record);

                if (!$target) {
                    return false;
                }

                if ($tenantId) {
                    if (!$this->tenantGuard->entityBelongsToTenant($target, (string) $tenantId)) {
                        return false;
                    }
                }

                if (!$this->customFieldsBag->matchWhereItems($partition['bag'], $target)) {
                    return false;
                }
            }

            if ($partition['native'] === []) {
                // Bag-only filter already matched (or empty bag list handled above).
                return $partition['bag'] !== [];
            }

            $tenantWhere = $tenantId
                ? $this->tenantGuard->tenantWhereForEntityType((string) $targetType, (string) $tenantId)
                : ['id' => null];

            $builder = $this->selectBuilderFactory
                ->create()
                ->from((string) $targetType)
                ->withWhere(WhereItem::fromRawAndGroup($partition['native']))
                ->buildQueryBuilder()
                ->where(['id' => $targetId])
                ->where($tenantWhere);

            $query = $builder->select(['id'])->limit(0, 1)->build();

            return $this->entityManager
                ->getRDBRepository((string) $targetType)
                ->clone($query)
                ->findOne() !== null;
        } catch (Throwable $e) {
            $this->log->warning('TransitionEvaluator: entityFilter failed: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * @param array<string, mixed> $node
     */
    private function evalEventHistory(array $node, Entity $record): bool
    {
        if (!class_exists('Espo\\Modules\\FeatureTrackingEvent\\Entities\\TrackingEvent')) {
            return false;
        }

        $code = $node['code'] ?? null;
        $minCount = (int) ($node['minCount'] ?? 1);
        $window = $node['window'] ?? null;
        $tenantId = $record->get('tenantId');
        $targetType = $record->get('targetType');
        $targetId = $record->get('targetId');

        if (!$code || !$tenantId) {
            return false;
        }

        try {
            $where = [
                'tenantId' => $tenantId,
                'code' => $code,
            ];

            if ($targetType === 'Contact') {
                $where['contactId'] = $targetId;
            } else {
                $where['parentType'] = $targetType;
                $where['parentId'] = $targetId;
            }

            if (is_string($window) && $window !== '') {
                $parser = new PeriodParser();
                $from = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
                    ->sub($parser->parse($window))
                    ->format('Y-m-d H:i:s');
                $where['occurredAt>='] = $from;
            }

            $count = $this->entityManager
                ->getRDBRepository('TrackingEvent')
                ->where($where)
                ->count();

            return $count >= $minCount;
        } catch (Throwable $e) {
            $this->log->warning('TransitionEvaluator: eventHistory failed: ' . $e->getMessage());

            return false;
        }
    }

    /**
     * @param array<string, mixed> $node
     * @param array{code?: string, payload?: array<string, mixed>}|null $signal
     */
    private function evalLegacyLeaf(array $node, Entity $record, ?array $signal): bool
    {
        if (isset($node['path']) || isset($node['field'])) {
            $path = (string) ($node['path'] ?? $node['field'] ?? '');
            $op = (string) ($node['operator'] ?? 'equals');
            $expected = $node['value'] ?? null;

            if (str_starts_with($path, 'event.payload') || str_starts_with($path, 'payload.')) {
                return $this->evalPayloadPath([
                    'path' => $path,
                    'operator' => $op,
                    'value' => $expected,
                ], $signal);
            }

            if ($path === 'event.code') {
                return $this->compare($signal['code'] ?? null, $op, $expected);
            }

            $target = $this->loadTarget($record);
            if ($target && str_starts_with($path, 'subject.')) {
                $attr = substr($path, strlen('subject.'));

                return $this->compare($target->get($attr), $op, $expected);
            }
        }

        return false;
    }

    private function loadTarget(Entity $record): ?Entity
    {
        $type = $record->get('targetType');
        $id = $record->get('targetId');

        if (!$type || !$id) {
            return null;
        }

        return $this->entityManager->getEntityById((string) $type, (string) $id);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function pathGet(array $data, string $path): mixed
    {
        if ($path === '' || $path === '.') {
            return $data;
        }

        $parts = explode('.', $path);
        $cur = $data;

        foreach ($parts as $p) {
            if ($p === '') {
                continue;
            }
            if (!is_array($cur) || !array_key_exists($p, $cur)) {
                return null;
            }
            $cur = $cur[$p];
        }

        return $cur;
    }

    private function compare(mixed $actual, string $op, mixed $expected): bool
    {
        if ($op === 'in' && is_string($expected)) {
            $decoded = json_decode($expected, true);
            $expected = is_array($decoded)
                ? $decoded
                : array_values(array_filter(
                    array_map('trim', explode(',', $expected)),
                    static fn (string $value): bool => $value !== '',
                ));
        }

        return match ($op) {
            'equals', '==' => $actual == $expected,
            'notEquals', '!=' => $actual != $expected,
            'contains' => is_string($actual) && is_string($expected) && str_contains($actual, $expected),
            'notContains' => is_string($actual) && is_string($expected) && !str_contains($actual, $expected),
            'exists' => $actual !== null && $actual !== '',
            'notExists' => $actual === null || $actual === '',
            'in' => is_array($expected) && in_array($actual, $expected, true),
            'greaterThan', '>' => is_numeric($actual) && is_numeric($expected) && (float) $actual > (float) $expected,
            'greaterThanOrEqual', 'greaterThanOrEquals', '>=' => is_numeric($actual) && is_numeric($expected) && (float) $actual >= (float) $expected,
            'lessThan', '<' => is_numeric($actual) && is_numeric($expected) && (float) $actual < (float) $expected,
            'lessThanOrEqual', 'lessThanOrEquals', '<=' => is_numeric($actual) && is_numeric($expected) && (float) $actual <= (float) $expected,
            default => false,
        };
    }
}

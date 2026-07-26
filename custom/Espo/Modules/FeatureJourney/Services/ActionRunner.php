<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureJourney\Services;

use Espo\Core\InjectableFactory;
use Espo\Core\Utils\Log;
use Espo\Core\Utils\Metadata;
use Espo\Modules\FeatureJourney\Classes\JourneyActions\Action;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use stdClass;
use Throwable;

class ActionRunner
{
    private const DEFAULT_MAX_RETRIES = 0;
    private const ABSOLUTE_MAX_RETRIES = 5;

    public function __construct(
        private EntityManager $entityManager,
        private Metadata $metadata,
        private InjectableFactory $injectableFactory,
        private JourneyRateLimiter $rateLimiter,
        private TenantGuard $tenantGuard,
        private RestrictedFormulaRunner $formulaRunner,
        private Log $log,
    ) {}

    /**
     * @return array{ok: bool, error?: string, skipped?: int, failed?: list<string>}
     */
    public function runForStage(
        Entity $stage,
        string $trigger,
        Entity $target,
        Entity $record,
        Entity $journey,
    ): array {
        $tenantId = $record->get('tenantId') ?: $journey->get('tenantId');
        $tenantId = $tenantId ? (string) $tenantId : null;

        if ($tenantId && !$this->rateLimiter->allowActions($tenantId)) {
            $this->log->warning("ActionRunner: rate limit hit for tenant {$tenantId}");

            return ['ok' => false, 'error' => 'rate_limited'];
        }

        $actions = $this->entityManager
            ->getRDBRepository('JourneyStageAction')
            ->where([
                'stageId' => $stage->getId(),
                'trigger' => $trigger,
                'isActive' => true,
            ])
            ->order('order', 'ASC')
            ->find();

        return $this->runActionsList(
            $actions,
            $target,
            $record,
            $stage,
            $journey,
            $trigger,
            $tenantId,
        );
    }

    /**
     * @param iterable<Entity> $actions
     * @return array{ok: bool, error?: string, skipped?: int, failed?: list<string>}
     */
    public function runActionsList(
        iterable $actions,
        Entity $target,
        Entity $record,
        Entity $stage,
        Entity $journey,
        string $trigger,
        ?string $tenantId,
    ): array {
        if ($tenantId) {
            try {
                $this->tenantGuard->assertEntityTenant($target, $tenantId, 'action-target');
                $this->tenantGuard->assertRecordMatchesJourney($record, $journey);
            } catch (Throwable $e) {
                $this->log->error('ActionRunner: tenant guard: ' . $e->getMessage());

                return ['ok' => false, 'error' => 'tenant_mismatch'];
            }
        }

        $skipped = 0;
        /** @var list<string> $failed */
        $failed = [];

        foreach ($actions as $actionEntity) {
            if (!$actionEntity->get('isActive')) {
                continue;
            }

            $type = (string) $actionEntity->get('type');
            $meta = $this->metadata->get(['app', 'journeyActionTypes', 'types', $type]);

            if (!is_array($meta) || empty($meta['implementationClassName'])) {
                $this->log->warning("ActionRunner: unknown action type '{$type}'");

                return ['ok' => false, 'error' => "unknown_type:{$type}"];
            }

            $className = (string) $meta['implementationClassName'];

            if (!class_exists($className)) {
                $this->log->warning("ActionRunner: class missing for type '{$type}': {$className}");

                return ['ok' => false, 'error' => "missing_class:{$type}"];
            }

            $params = $this->normalizeParams($actionEntity->get('params'));

            // Dedicated entity field overrides params.formula for executeFormula UI.
            $formulaField = $actionEntity->get('formula');
            if (is_string($formulaField) && trim($formulaField) !== '') {
                $params['formula'] = $formulaField;
            }

            // Never let param tenantId override journey tenant.
            unset($params['tenantId']);

            $params = $this->resolveParamFormulas(
                $params,
                $target,
                $record,
                $stage,
                $journey,
                $tenantId,
            );

            $maxRetries = (int) ($actionEntity->get('maxRetries') ?? self::DEFAULT_MAX_RETRIES);
            $maxRetries = max(0, min(self::ABSOLUTE_MAX_RETRIES, $maxRetries));
            $continueOnError = (bool) $actionEntity->get('continueOnError');

            $ctx = new ActionContext(
                target: $target,
                record: $record,
                stage: $stage,
                journey: $journey,
                trigger: $trigger,
                params: $params,
                tenantId: $tenantId,
            );

            $lastError = null;
            $succeeded = false;

            for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
                try {
                    /** @var Action $impl */
                    $impl = $this->injectableFactory->create($className);
                    $impl->run($ctx);
                    $succeeded = true;
                    break;
                } catch (Throwable $e) {
                    $lastError = $e->getMessage();
                    $this->log->error(
                        "ActionRunner: action {$actionEntity->getId()} ({$type}) " .
                        "attempt " . ($attempt + 1) . "/" . ($maxRetries + 1) .
                        " failed: {$lastError}"
                    );
                }
            }

            if ($succeeded) {
                continue;
            }

            $failed[] = "{$type}:" . ($lastError ?? 'unknown');

            if ($continueOnError) {
                $skipped++;
                $this->log->warning(
                    "ActionRunner: continueOnError skip action {$actionEntity->getId()} ({$type})"
                );

                continue;
            }

            return [
                'ok' => false,
                'error' => $lastError ?? 'action_failed',
                'failed' => $failed,
                'skipped' => $skipped,
            ];
        }

        return [
            'ok' => true,
            'skipped' => $skipped,
            'failed' => $failed,
        ];
    }

    /**
     * Evaluate params.paramFormulas (map of key → formula) in MODE_CONDITION (read-only)
     * and merge results into params. Overwrites static keys of the same name.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function resolveParamFormulas(
        array $params,
        Entity $target,
        Entity $record,
        Entity $stage,
        Entity $journey,
        ?string $tenantId,
    ): array {
        $formulas = $params['paramFormulas'] ?? null;
        unset($params['paramFormulas']);

        if ($formulas instanceof stdClass) {
            $formulas = (array) $formulas;
        }

        if (!is_array($formulas) || $formulas === []) {
            return $params;
        }

        $variables = (object) [
            'journeyRecordId' => $record->getId(),
            'journeyId' => $journey->getId(),
            'stageId' => $stage->getId(),
            'tenantId' => $tenantId,
        ];

        foreach ($formulas as $key => $script) {
            if (!is_string($key) || $key === '' || !$this->isParamFormulaKeyAllowed($key)) {
                continue;
            }

            if (!is_string($script) || trim($script) === '') {
                continue;
            }

            try {
                $value = $this->formulaRunner->run(
                    $script,
                    $target,
                    $variables,
                    RestrictedFormulaRunner::MODE_CONDITION,
                );
                $this->assignParamPath($params, $key, $value);
            } catch (Throwable $e) {
                $this->log->error(
                    "ActionRunner: paramFormulas.{$key} failed: " . $e->getMessage()
                );
                throw $e;
            }
        }

        return $params;
    }

    /**
     * Top-level keys or dotted paths under fields.* (updateTarget fieldMap).
     * Never allows tenantId at any segment.
     */
    private function isParamFormulaKeyAllowed(string $key): bool
    {
        if ($key === 'tenantId' || str_ends_with($key, '.tenantId')) {
            return false;
        }

        if (!str_contains($key, '.')) {
            return true;
        }

        // Nested: fields.<attribute> (attribute may contain dots, e.g. customFields.billing.plan)
        if (!str_starts_with($key, 'fields.')) {
            return false;
        }

        return substr($key, strlen('fields.')) !== '';
    }

    /**
     * @param array<string, mixed> $params
     */
    private function assignParamPath(array &$params, string $key, mixed $value): void
    {
        if (!str_contains($key, '.')) {
            $params[$key] = $value;

            return;
        }

        // fields.<fieldKey> — fieldKey may contain dots (customFields.valueKey)
        if (str_starts_with($key, 'fields.')) {
            $fieldKey = substr($key, strlen('fields.'));

            if ($fieldKey === '' || $fieldKey === 'tenantId') {
                return;
            }

            if (!isset($params['fields']) || !is_array($params['fields'])) {
                $params['fields'] = [];
            }

            $params['fields'][$fieldKey] = $value;

            return;
        }

        $params[$key] = $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeParams(mixed $params): array
    {
        if ($params instanceof stdClass) {
            return (array) $params;
        }

        if (is_array($params)) {
            return $params;
        }

        return [];
    }
}

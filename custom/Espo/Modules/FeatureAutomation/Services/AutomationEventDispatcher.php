<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureAutomation\Services;

use Espo\Core\Utils\Log;
use Espo\Modules\FeatureAutomation\Entities\Automation;
use Espo\ORM\EntityManager;
use Throwable;

/**
 * Wakes Active automations whose triggerType is entityChange / signal.
 * Supports triggerDebouncePeriod via ActionReceiptStore.
 */
class AutomationEventDispatcher
{
    public function __construct(
        private EntityManager $entityManager,
        private AutomationRunner $runner,
        private ActionReceiptStore $receiptStore,
        private AutomationTriggerFilterEvaluator $triggerFilterEvaluator,
        private Log $log,
    ) {}

    /**
     * @param array<string, mixed> $extra
     */
    public function dispatchEntityChange(
        string $entityType,
        string $entityId,
        string $event = 'update',
        array $extra = [],
    ): void {
        try {
            $list = $this->entityManager
                ->getRDBRepository(Automation::ENTITY_TYPE)
                ->where([
                    'status' => Automation::STATUS_ACTIVE,
                    'triggerType' => Automation::TRIGGER_ENTITY_CHANGE,
                ])
                ->find();

            foreach ($list as $automation) {
                if (!$this->matchesSubjectEntityType($automation, $entityType)) {
                    continue;
                }

                $payload = array_merge($extra, [
                    'entityType' => $entityType,
                    'entityId' => $entityId,
                    'event' => $event,
                ]);

                if (!$this->triggerFilterEvaluator->matches($automation, $entityType, $entityId, $payload)) {
                    continue;
                }

                if (!$this->passTriggerDebounce($automation, "entityChange|{$entityType}|{$entityId}|{$event}")) {
                    continue;
                }

                try {
                    $this->runner->startRun($automation, 'entityChange', $payload);
                } catch (Throwable $e) {
                    $this->log->error(
                        'AutomationEventDispatcher entityChange ' .
                        $automation->getId() . ': ' . $e->getMessage()
                    );
                }
            }
        } catch (Throwable $e) {
            $this->log->error('AutomationEventDispatcher: ' . $e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $extra
     */
    public function dispatchSignal(
        string $code,
        ?string $entityType = null,
        ?string $entityId = null,
        array $extra = [],
    ): void {
        if ($code === '') {
            return;
        }

        try {
            $list = $this->entityManager
                ->getRDBRepository(Automation::ENTITY_TYPE)
                ->where([
                    'status' => Automation::STATUS_ACTIVE,
                    'triggerType' => Automation::TRIGGER_SIGNAL,
                ])
                ->find();

            foreach ($list as $automation) {
                $codes = $automation->get('signalCodes');
                if ($codes instanceof \stdClass) {
                    $codes = (array) $codes;
                }
                if (!is_array($codes)) {
                    $codes = [];
                }

                $codes = array_map('strval', $codes);
                if ($codes !== [] && !in_array($code, $codes, true)) {
                    continue;
                }

                if (!$this->matchesSubjectEntityType($automation, $entityType)) {
                    continue;
                }

                $payload = array_merge($extra, [
                    'signal' => $code,
                    'entityType' => $entityType,
                    'entityId' => $entityId,
                ]);

                if (!$this->triggerFilterEvaluator->matches($automation, $entityType, $entityId, $payload)) {
                    continue;
                }

                $fp = 'signal|' . $code . '|' . ($entityType ?? '') . '|' . ($entityId ?? '');
                if (!$this->passTriggerDebounce($automation, $fp)) {
                    continue;
                }

                try {
                    $this->runner->startRun($automation, 'signal', $payload);
                } catch (Throwable $e) {
                    $this->log->error(
                        'AutomationEventDispatcher signal ' .
                        $automation->getId() . ': ' . $e->getMessage()
                    );
                }
            }
        } catch (Throwable $e) {
            $this->log->error('AutomationEventDispatcher signal: ' . $e->getMessage());
        }
    }

    private function passTriggerDebounce(object $automation, string $fingerprint): bool
    {
        $period = trim((string) ($automation->get('triggerDebouncePeriod') ?? ''));
        if ($period === '') {
            return true;
        }

        $tenantId = $automation->get('tenantId') ? (string) $automation->get('tenantId') : null;

        return $this->receiptStore->tryClaim(
            (string) $automation->getId(),
            $fingerprint,
            'trigger',
            $period,
            $tenantId,
        );
    }

    private function matchesSubjectEntityType(Automation $automation, ?string $entityType): bool
    {
        $expected = trim((string) ($automation->get('subjectEntityType') ?? ''));

        if ($expected === '') {
            $legacy = $automation->get('entityTypeFilter');

            if (is_string($legacy) && !str_starts_with(ltrim($legacy), '{') && !str_starts_with(ltrim($legacy), '[')) {
                $expected = trim($legacy);
            }
        }

        if ($expected === '') {
            return true;
        }

        return $entityType !== null && $entityType === $expected;
    }
}

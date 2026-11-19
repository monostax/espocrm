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

            $subjectTenants = [];

            foreach ($list as $automation) {
                if (!$this->matchesSubjectEntityType($automation, $entityType)) {
                    continue;
                }

                if (!$this->matchesSubjectTenant($automation, $entityType, $entityId, $subjectTenants)) {
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

            $subjectTenants = [];

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

                if (!$this->matchesSubjectTenant($automation, $entityType, $entityId, $subjectTenants)) {
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

    /**
     * Whether any Active signal automation would accept `$code` (an empty code list accepts
     * every signal). Lets producers skip preparing a signal nobody listens to.
     */
    public function hasSignalListeners(string $code): bool
    {
        if ($code === '') {
            return false;
        }

        $list = $this->entityManager
            ->getRDBRepository(Automation::ENTITY_TYPE)
            ->select(['id', 'signalCodes'])
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
            $codes = is_array($codes) ? array_map('strval', $codes) : [];

            if ($codes === [] || in_array($code, $codes, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A workspace automation only reacts to records of its own workspace. Starting it for
     * another workspace's record would fail the run (machine subjects are tenant-guarded)
     * or, for batch runs, act on its own records because of someone else's event.
     * Workspace-less (instance) and cross-tenant automations keep reacting to every record.
     *
     * @param array<string, ?string> $cache subject tenant per "type|id" for one dispatch
     */
    private function matchesSubjectTenant(
        Automation $automation,
        ?string $entityType,
        ?string $entityId,
        array &$cache,
    ): bool {
        $tenantId = (string) ($automation->get('tenantId') ?? '');
        if ($tenantId === '' || !$entityType || !$entityId || $automation->get('crossTenant')) {
            return true;
        }

        if ($entityType === 'Tenant') {
            return $entityId === $tenantId;
        }

        $cacheKey = $entityType . '|' . $entityId;
        if (!array_key_exists($cacheKey, $cache)) {
            $subject = $this->entityManager->getEntityById($entityType, $entityId);
            $cache[$cacheKey] = $subject && $subject->hasAttribute('tenantId')
                ? (string) ($subject->get('tenantId') ?? '')
                : null;
        }

        $subjectTenantId = $cache[$cacheKey];

        return $subjectTenantId === null || $subjectTenantId === '' || $subjectTenantId === $tenantId;
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

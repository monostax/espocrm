<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureAutomation\Services;

use Espo\Core\ORM\Repository\Option\SaveOption;
use Espo\Core\Utils\Log;
use Espo\Modules\FeatureAutomation\Entities\AutomationRun;
use Espo\Modules\FeatureAutomation\Entities\AutomationRunItem;
use Espo\ORM\EntityManager;
use Throwable;

/**
 * Fan-in: when a child run reaches a terminal status, wake the parent item
 * if waitAll / waitAny is satisfied.
 */
class JoinCoordinator
{
    /** @var list<string> */
    private const TERMINAL = [
        AutomationRun::STATUS_COMPLETED,
        AutomationRun::STATUS_FAILED,
        AutomationRun::STATUS_CANCELLED,
    ];

    /** @var list<string> */
    private array $wokenRunIds = [];

    public function __construct(
        private EntityManager $entityManager,
        private Log $log,
    ) {}

    /**
     * @return list<string> Parent run ids that were woken and need dispatch
     */
    public function drainWokenRunIds(): array
    {
        $ids = $this->wokenRunIds;
        $this->wokenRunIds = [];

        return $ids;
    }

    /**
     * Called after a run becomes terminal.
     *
     * @return list<string> Parent run ids woken
     */
    public function onChildRunTerminal(string $runId): array
    {
        $run = $this->entityManager->getEntityById(AutomationRun::ENTITY_TYPE, $runId);
        if (!$run) {
            return [];
        }

        $parentItemId = $run->get('parentRunItemId')
            ? (string) $run->get('parentRunItemId')
            : '';

        if ($parentItemId === '') {
            $payload = $run->get('triggerPayload');
            if ($payload instanceof \stdClass) {
                $payload = json_decode(json_encode($payload) ?: '{}', true) ?: [];
            }
            if (is_array($payload) && !empty($payload['_parentRunItemId'])) {
                $parentItemId = (string) $payload['_parentRunItemId'];
            }
        }

        if ($parentItemId === '') {
            return [];
        }

        $this->checkAndWakeParent($parentItemId);

        return $this->drainWokenRunIds();
    }

    public function checkAndWakeParent(string $parentItemId): void
    {
        $item = $this->entityManager->getEntityById(AutomationRunItem::ENTITY_TYPE, $parentItemId);
        if (!$item) {
            return;
        }

        if ($item->get('status') !== AutomationRunItem::STATUS_WAITING) {
            // Still refresh join snapshot for delayed waits mid-action
        }

        $payload = $this->toArray($item->get('payload'));
        $joinMode = (string) ($payload['_joinMode'] ?? 'waitAll');
        $pending = $payload['_pendingJoins'] ?? [];
        if (!is_array($pending) || $pending === []) {
            return;
        }

        $results = [];
        $completed = 0;
        $failed = 0;
        $running = 0;
        $total = 0;

        foreach ($pending as $entry) {
            if (!is_array($entry) && !($entry instanceof \stdClass)) {
                continue;
            }
            $row = is_array($entry) ? $entry : (array) $entry;
            $childRunId = (string) ($row['runId'] ?? '');
            if ($childRunId === '') {
                continue;
            }
            $total++;
            $child = $this->entityManager->getEntityById(AutomationRun::ENTITY_TYPE, $childRunId);
            $status = $child ? (string) $child->get('status') : 'Missing';
            $results[] = [
                'runId' => $childRunId,
                'automationId' => $row['automationId'] ?? ($child?->get('automationId')),
                'status' => $status,
                'failedCount' => $child ? (int) $child->get('failedCount') : null,
                'doneCount' => $child ? (int) $child->get('doneCount') : null,
            ];

            if ($status === AutomationRun::STATUS_COMPLETED) {
                $completed++;
            } elseif (in_array($status, [AutomationRun::STATUS_FAILED, AutomationRun::STATUS_CANCELLED, 'Missing'], true)) {
                $failed++;
            } else {
                $running++;
            }
        }

        $payload['_joinResults'] = $results;
        $payload['_joinStats'] = [
            'total' => $total,
            'completed' => $completed,
            'failed' => $failed,
            'running' => $running,
            'checkedAt' => date('Y-m-d H:i:s'),
        ];

        $satisfied = false;
        if ($joinMode === 'waitAny') {
            $satisfied = $completed > 0 || $failed > 0;
        } else {
            // waitAll — all terminal
            $satisfied = $total > 0 && $running === 0;
        }

        $payload['_joinSatisfied'] = $satisfied;

        $item->set('payload', $payload);
        $this->entityManager->saveEntity($item, [SaveOption::SKIP_ALL => true]);

        if (!$satisfied || $item->get('status') !== AutomationRunItem::STATUS_WAITING) {
            return;
        }

        // Join-driven wake (even before timeout)
        $item->set([
            'status' => AutomationRunItem::STATUS_RETRY,
            'wakeAt' => null,
            'claimedAt' => null,
            'errorMessage' => null,
        ]);
        $this->entityManager->saveEntity($item, [SaveOption::SKIP_ALL => true]);

        $parentRunId = (string) $item->get('runId');
        if ($parentRunId !== '') {
            $this->wokenRunIds[] = $parentRunId;
        }

        $this->log->info("JoinCoordinator woke parent item {$parentItemId} ({$joinMode})");
    }

    /**
     * Evaluate join readiness without writing wake (used by waitJoin action / machine join state).
     *
     * @param array<string, mixed> $payload
     * @return array{satisfied: bool, payload: array<string, mixed>, results: list<array<string, mixed>>}
     */
    public function evaluate(array $payload, string $joinMode = 'waitAll'): array
    {
        $pending = $payload['_pendingJoins'] ?? [];
        if (!is_array($pending)) {
            $pending = [];
        }

        $results = [];
        $completed = 0;
        $failed = 0;
        $running = 0;
        $total = 0;

        foreach ($pending as $entry) {
            if ($entry instanceof \stdClass) {
                $entry = (array) $entry;
            }
            if (!is_array($entry)) {
                continue;
            }
            $childRunId = (string) ($entry['runId'] ?? '');
            if ($childRunId === '') {
                continue;
            }
            $total++;
            $child = $this->entityManager->getEntityById(AutomationRun::ENTITY_TYPE, $childRunId);
            $status = $child ? (string) $child->get('status') : 'Missing';
            $results[] = [
                'runId' => $childRunId,
                'automationId' => $entry['automationId'] ?? ($child?->get('automationId')),
                'status' => $status,
            ];
            if ($status === AutomationRun::STATUS_COMPLETED) {
                $completed++;
            } elseif (in_array($status, [AutomationRun::STATUS_FAILED, AutomationRun::STATUS_CANCELLED, 'Missing'], true)) {
                $failed++;
            } else {
                $running++;
            }
        }

        $satisfied = $joinMode === 'waitAny'
            ? ($completed > 0 || $failed > 0)
            : ($total > 0 && $running === 0);

        // No children yet → not satisfied (wait for startChild)
        if ($total === 0) {
            $satisfied = false;
        }

        $payload['_joinResults'] = $results;
        $payload['_joinStats'] = [
            'total' => $total,
            'completed' => $completed,
            'failed' => $failed,
            'running' => $running,
            'checkedAt' => date('Y-m-d H:i:s'),
        ];
        $payload['_joinSatisfied'] = $satisfied;
        $payload['_joinMode'] = $joinMode;

        return [
            'satisfied' => $satisfied,
            'payload' => $payload,
            'results' => $results,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function toArray(mixed $value): array
    {
        if ($value instanceof \stdClass) {
            return json_decode(json_encode($value) ?: '{}', true) ?: [];
        }

        return is_array($value) ? $value : [];
    }
}

<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureAutomation\Classes\AutomationActions;

use Espo\Core\Exceptions\Error;
use Espo\Core\InjectableFactory;
use Espo\Core\ORM\Repository\Option\SaveOption;
use Espo\Modules\FeatureAutomation\Services\AutomationRunner;
use Espo\Modules\FeatureJourney\Classes\JourneyActions\Action;
use Espo\Modules\FeatureJourney\Services\ActionContext;
use Espo\ORM\EntityManager;

/**
 * Nested Batch/Machine: start another Automation for the same (or override) subject.
 *
 * params:
 *  - automationId (required)
 *  - entityType / entityId optional override (default: current target)
 *  - passPayload bool (default true) — merge parent payload into child trigger
 *
 * Writes child run id onto parent item payload._pendingJoins[] for fan-in (waitJoin).
 * Skips re-start when the same child key is already pending (re-entrant on wake).
 */
class StartChildAutomation implements Action
{
    public function __construct(
        private InjectableFactory $injectableFactory,
        private EntityManager $entityManager,
    ) {}

    public function run(ActionContext $ctx): void
    {
        $params = $ctx->params;
        $automationId = (string) ($params['automationId'] ?? '');
        if ($automationId === '') {
            throw new Error('startChildAutomation requires automationId.');
        }

        $target = $ctx->target;
        $entityType = (string) ($params['entityType'] ?? $target->getEntityType());
        $entityId = (string) ($params['entityId'] ?? $target->getId());

        $parentPayload = [];
        $record = $ctx->record;
        if ($record && $record->has('payload')) {
            $raw = $record->get('payload');
            if ($raw instanceof \stdClass) {
                $raw = json_decode(json_encode($raw) ?: '{}', true) ?: [];
            }
            if (is_array($raw)) {
                $parentPayload = $raw;
            }
        }

        $childKey = $automationId . '|' . $entityType . '|' . $entityId;
        $pending = $parentPayload['_pendingJoins'] ?? [];
        if (!is_array($pending)) {
            $pending = [];
        }

        foreach ($pending as $entry) {
            if ($entry instanceof \stdClass) {
                $entry = (array) $entry;
            }
            if (!is_array($entry)) {
                continue;
            }
            $existingKey = (string) ($entry['key'] ?? '');
            if ($existingKey === $childKey) {
                // Already spawned for this subject — skip re-fire on join wake
                return;
            }
        }

        $depth = (int) ($parentPayload['_depth'] ?? 0);
        $parentRunId = $record && $record->has('runId') ? (string) $record->get('runId') : null;

        $trigger = [
            'entityType' => $entityType,
            'entityId' => $entityId,
            '_depth' => $depth,
            '_parentAutomationId' => $ctx->journey->getId(),
            '_parentRunItemId' => $record ? $record->getId() : null,
            '_parentRunId' => $parentRunId,
        ];

        if (($params['passPayload'] ?? true) && $parentPayload !== []) {
            $trigger['_parentPayload'] = $parentPayload;
            if (isset($parentPayload['_trigger']) && is_array($parentPayload['_trigger'])) {
                $trigger = array_merge($parentPayload['_trigger'], $trigger);
            }
        }

        /** @var AutomationRunner $runner */
        $runner = $this->injectableFactory->create(AutomationRunner::class);
        $childRun = $runner->startChildRun($automationId, $trigger);

        $pending[] = [
            'key' => $childKey,
            'runId' => $childRun->getId(),
            'automationId' => $automationId,
            'entityType' => $entityType,
            'entityId' => $entityId,
            'startedAt' => date('Y-m-d H:i:s'),
        ];
        $parentPayload['_pendingJoins'] = $pending;

        if ($record && $record->hasId()) {
            $record->set('payload', $parentPayload);
            $this->entityManager->saveEntity($record, [SaveOption::SKIP_ALL => true]);
        }
    }
}

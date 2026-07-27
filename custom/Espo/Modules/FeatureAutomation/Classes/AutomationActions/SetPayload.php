<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureAutomation\Classes\AutomationActions;

use Espo\Core\Exceptions\Error;
use Espo\Core\ORM\Repository\Option\SaveOption;
use Espo\Modules\FeatureAutomation\Services\PayloadBag;
use Espo\Modules\FeatureJourney\Classes\JourneyActions\Action;
use Espo\Modules\FeatureJourney\Services\ActionContext;
use Espo\ORM\EntityManager;
use stdClass;

/**
 * Write values into AutomationRunItem.payload for later actions / formulas.
 *
 * params:
 *  - path + value (single assignment), and/or
 *  - assignments: [{path, value, merge?}, ...]
 *  - merge: bool (deep-merge objects when true)
 *
 * Prefer paramFormulas.value (or assignments via JSON + formulas) for dynamic data.
 * Alias action type: assign
 */
class SetPayload implements Action
{
    public function __construct(
        private EntityManager $entityManager,
        private PayloadBag $payloadBag,
    ) {}

    public function run(ActionContext $ctx): void
    {
        $params = $ctx->params;
        $record = $ctx->record;
        if (!$record) {
            throw new Error('setPayload: missing run item record.');
        }

        $assignments = $this->collectAssignments($params);
        if ($assignments === []) {
            throw new Error('setPayload requires path+value or non-empty assignments[].');
        }

        $payload = $this->payloadBag->normalize($record->get('payload'));
        $defaultMerge = (bool) ($params['merge'] ?? false);

        foreach ($assignments as $row) {
            $path = (string) ($row['path'] ?? '');
            $merge = array_key_exists('merge', $row) ? (bool) $row['merge'] : $defaultMerge;
            $payload = $this->payloadBag->setByPath(
                $payload,
                $path,
                $row['value'] ?? null,
                $merge
            );
        }

        $record->set('payload', $payload);
        if ($record->hasId() && $record->getId() && !str_starts_with((string) $record->getId(), 'sim_')) {
            $this->entityManager->saveEntity($record, [SaveOption::SKIP_ALL => true]);
        }
    }

    /**
     * @param array<string, mixed> $params
     * @return list<array{path: string, value: mixed, merge?: bool}>
     */
    private function collectAssignments(array $params): array
    {
        $out = [];

        $path = isset($params['path']) ? trim((string) $params['path']) : '';
        if ($path !== '' && array_key_exists('value', $params)) {
            $out[] = [
                'path' => $path,
                'value' => $params['value'],
                'merge' => isset($params['merge']) ? (bool) $params['merge'] : null,
            ];
        }

        $list = $params['assignments'] ?? null;
        if ($list instanceof stdClass) {
            $list = json_decode(json_encode($list) ?: '[]', true);
        }
        if (is_array($list)) {
            foreach ($list as $item) {
                if ($item instanceof stdClass) {
                    $item = json_decode(json_encode($item) ?: '{}', true);
                }
                if (!is_array($item)) {
                    continue;
                }
                $p = trim((string) ($item['path'] ?? ''));
                if ($p === '' || !array_key_exists('value', $item)) {
                    continue;
                }
                $row = ['path' => $p, 'value' => $item['value']];
                if (array_key_exists('merge', $item)) {
                    $row['merge'] = (bool) $item['merge'];
                }
                $out[] = $row;
            }
        }

        // normalize null merge key
        foreach ($out as $i => $row) {
            if (array_key_exists('merge', $row) && $row['merge'] === null) {
                unset($out[$i]['merge']);
            }
        }

        return array_values($out);
    }
}

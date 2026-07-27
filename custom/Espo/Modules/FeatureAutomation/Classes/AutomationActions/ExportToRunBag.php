<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureAutomation\Classes\AutomationActions;

use Espo\Core\Exceptions\Error;
use Espo\Modules\FeatureAutomation\Entities\AutomationRun;
use Espo\Modules\FeatureAutomation\Services\PayloadBag;
use Espo\Modules\FeatureAutomation\Services\RunDataBag;
use Espo\Modules\FeatureJourney\Classes\JourneyActions\Action;
use Espo\Modules\FeatureJourney\Services\ActionContext;
use Espo\ORM\EntityManager;
use stdClass;

/**
 * Copy selected payload keys into the AutomationRun data bag (cross-stage).
 *
 * params:
 *  - keys: string[] | comma-string | omit/true for all public roots
 *  - mode: merge|replace (default merge)
 *  - path: optional single payload path root to export (alias of keys: [path])
 */
class ExportToRunBag implements Action
{
    public function __construct(
        private EntityManager $entityManager,
        private RunDataBag $runDataBag,
        private PayloadBag $payloadBag,
    ) {}

    public function run(ActionContext $ctx): void
    {
        $record = $ctx->record;
        if (!$record) {
            throw new Error('exportToRunBag: missing run item record.');
        }

        $runId = $record->get('runId') ? (string) $record->get('runId') : '';
        if ($runId === '' || str_starts_with($runId, 'sim_')) {
            // Simulate / dry-run: no durable run bag.
            return;
        }

        $run = $this->entityManager->getEntityById(AutomationRun::ENTITY_TYPE, $runId);
        if (!$run) {
            throw new Error('exportToRunBag: AutomationRun not found.');
        }

        $params = $ctx->params;
        $mode = strtolower(trim((string) ($params['mode'] ?? 'merge'))) === 'replace'
            ? 'replace'
            : 'merge';

        $keys = $this->resolveKeys($params);
        $payload = $this->payloadBag->normalize($record->get('payload'));

        $this->runDataBag->exportFromPayload($run, $payload, $keys, $mode, true);
    }

    /**
     * @param array<string, mixed> $params
     * @return list<string>|true
     */
    private function resolveKeys(array $params): array|bool
    {
        if (isset($params['path']) && is_string($params['path']) && trim($params['path']) !== '') {
            $root = explode('.', trim($params['path']))[0];

            return [$root];
        }

        if (!array_key_exists('keys', $params)) {
            return true;
        }

        $raw = $params['keys'];
        if ($raw === true || $raw === '*' || $raw === 'all' || $raw === null) {
            return true;
        }
        if ($raw === false) {
            throw new Error('exportToRunBag: keys cannot be false; omit keys to export all public roots.');
        }
        if (is_string($raw)) {
            $cfg = $this->runDataBag->normalizeExportConfig($raw);
            if ($cfg === null) {
                throw new Error('exportToRunBag: invalid keys string.');
            }

            return $cfg['keys'];
        }
        if ($raw instanceof stdClass) {
            $raw = json_decode(json_encode($raw) ?: '[]', true);
        }
        if (is_array($raw)) {
            $cfg = $this->runDataBag->normalizeExportConfig($raw);
            if ($cfg === null) {
                throw new Error('exportToRunBag: invalid keys list.');
            }

            return $cfg['keys'];
        }

        throw new Error('exportToRunBag: keys must be true, a comma-list, or a string[].');
    }
}

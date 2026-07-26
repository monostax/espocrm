<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureJourney\Classes\JourneyActions;

use Espo\Modules\FeatureJourney\Services\ActionContext;
use Espo\Modules\FeatureJourney\Services\RestrictedFormulaRunner;

/**
 * Runs restricted formula in MODE_ACTION (journey\* + updateTarget allowed).
 * Depth is enforced inside each journey\* function — not double-counted here.
 */
class ExecuteFormula implements Action
{
    public function __construct(
        private RestrictedFormulaRunner $formulaRunner,
    ) {}

    public function run(ActionContext $context): void
    {
        $script = (string) ($context->params['formula'] ?? $context->params['script'] ?? '');
        if (trim($script) === '') {
            return;
        }

        $this->formulaRunner->run(
            $script,
            $context->target,
            (object) [
                'journeyRecordId' => $context->record->getId(),
                'journeyId' => $context->journey->getId(),
                'stageId' => $context->stage->getId(),
                'tenantId' => $context->tenantId,
            ],
            RestrictedFormulaRunner::MODE_ACTION,
        );
    }
}

<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureAutomation\Classes\AutomationActions;

use Espo\Modules\FeatureJourney\Classes\JourneyActions\Action;
use Espo\Modules\FeatureJourney\Services\ActionContext;

/**
 * Marker action — handled by AutomationActionRunner (never called live).
 * Params: at (datetime), timezone (optional IANA, default UTC when at has no offset).
 * Prefer paramFormulas.at for dynamic times (e.g. entity closeDate).
 */
class WaitUntil implements Action
{
    public function run(ActionContext $ctx): void
    {
        // ActionRunner intercepts waitUntil before this is invoked.
    }
}

<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureAutomation\Classes\AutomationActions;

use Espo\Modules\FeatureJourney\Classes\JourneyActions\Action;
use Espo\Modules\FeatureJourney\Services\ActionContext;

/**
 * Marker action — handled specially by AutomationActionRunner (never called live).
 * Params: mode (waitAll|waitAny), timeoutPeriod (e.g. "2 hours").
 */
class WaitJoin implements Action
{
    public function run(ActionContext $ctx): void
    {
        // ActionRunner intercepts waitJoin before this is invoked.
    }
}

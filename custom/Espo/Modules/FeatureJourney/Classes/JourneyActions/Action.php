<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureJourney\Classes\JourneyActions;

use Espo\Modules\FeatureJourney\Services\ActionContext;

interface Action
{
    public function run(ActionContext $context): void;
}

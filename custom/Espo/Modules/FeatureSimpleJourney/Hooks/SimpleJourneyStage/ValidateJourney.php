<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureSimpleJourney\Hooks\SimpleJourneyStage;

use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Modules\FeatureSimpleJourney\Services\JourneyAccess;
use Espo\ORM\Entity;
use Espo\ORM\Repository\Option\SaveOptions;

class ValidateJourney implements BeforeSave
{
    public static int $order = 10;

    public function __construct(private JourneyAccess $journeyAccess) {}

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        $this->journeyAccess->requireParent($entity, 'edit');
    }
}

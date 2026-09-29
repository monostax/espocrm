<?php

declare(strict_types=1);

namespace Espo\Modules\Global\Hooks\Opportunity;

use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Modules\Global\Tools\Opportunity\StageRequirements;
use Espo\ORM\Entity;
use Espo\ORM\Repository\Option\SaveOptions;

class ValidateStageRequirements implements BeforeSave
{
    // After tenant/stage synchronization, before stage timing and persistence.
    public static int $order = 85;

    public function __construct(private StageRequirements $requirements) {}

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        $this->requirements->validate($entity);
    }
}

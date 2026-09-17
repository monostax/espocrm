<?php

declare(strict_types=1);

namespace Espo\Modules\Global\Hooks\OpportunityStage;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Hook\Hook\BeforeSave;
use Espo\ORM\Entity;
use Espo\ORM\Repository\Option\SaveOptions;

class ValidateTargetTime implements BeforeSave
{
    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        $target = $entity->get('targetTimeSeconds');
        if ($target !== null && ($target < 60 || $target > 2147483640 || $target % 60 !== 0)) {
            throw new BadRequest('Target time must be a positive whole number of minutes, or empty.');
        }
    }
}

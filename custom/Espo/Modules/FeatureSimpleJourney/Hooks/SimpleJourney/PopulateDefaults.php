<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureSimpleJourney\Hooks\SimpleJourney;

use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Modules\FeatureSimpleJourney\Services\Defaults;
use Espo\ORM\Entity;
use Espo\ORM\Repository\Option\SaveOptions;

class PopulateDefaults implements BeforeSave
{
    public static int $order = 1;

    public function __construct(private Defaults $defaults) {}

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        $this->defaults->process($entity);
    }
}

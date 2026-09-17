<?php

declare(strict_types=1);

namespace Espo\Modules\Global\Hooks\Opportunity;

use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Core\Hook\Hook\AfterSave;
use Espo\Modules\Global\Tools\Opportunity\StageTiming;
use Espo\ORM\Entity;
use Espo\ORM\Repository\Option\SaveOptions;

class TrackStageTime implements BeforeSave, AfterSave
{
    // After stage/status synchronization and validation. Both phases are in the save transaction.
    public static int $order = 90;

    public function __construct(private StageTiming $timing) {}

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        $this->timing->prepare($entity);
    }

    public function afterSave(Entity $entity, SaveOptions $options): void
    {
        $this->timing->complete($entity);
    }
}

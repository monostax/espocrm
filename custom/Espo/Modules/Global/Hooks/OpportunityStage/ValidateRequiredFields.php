<?php

declare(strict_types=1);

namespace Espo\Modules\Global\Hooks\OpportunityStage;

use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Modules\Global\Tools\Opportunity\StageRequirements;
use Espo\ORM\Entity;
use Espo\ORM\Repository\Option\SaveOptions;

class ValidateRequiredFields implements BeforeSave
{
    public static int $order = 15;

    public function __construct(private StageRequirements $requirements) {}

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        if ($entity->isNew() || $entity->isAttributeChanged('requiredCustomFieldKeys') ||
            $entity->isAttributeChanged('funnelId')) {
            $this->requirements->getFields($entity);
        }
    }
}

<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureInitiative\Hooks\InitiativeStage;

use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Modules\FeatureInitiative\Hooks\Initiative\ValidateProgress;
use Espo\Modules\FeatureInitiative\Services\InitiativeTypeAccess;
use Espo\Modules\FeatureInitiative\Services\ValidationError;
use Espo\ORM\Entity;
use Espo\ORM\Repository\Option\SaveOptions;

class ValidateInitiativeType implements BeforeSave
{
    public static int $order = 10;

    public function __construct(private InitiativeTypeAccess $initiativeTypeAccess) {}

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        $this->initiativeTypeAccess->requireParent($entity, 'edit');

        if ($entity->isNew() && $entity->get('category') === null) {
            $entity->set('category', 'Open');
        }

        if (!in_array($entity->get('category'), ValidateProgress::STATUSES, true)) {
            throw ValidationError::badRequest('invalidCategory', 'Stage category must be Open, In Progress, Paused, Completed or Canceled.');
        }
    }
}

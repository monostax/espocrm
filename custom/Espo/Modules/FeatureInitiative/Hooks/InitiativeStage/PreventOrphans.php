<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureInitiative\Hooks\InitiativeStage;

use Espo\Modules\FeatureInitiative\Services\ValidationError;
use Espo\Core\Hook\Hook\BeforeRemove;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\RemoveOptions;

class PreventOrphans implements BeforeRemove
{
    public function __construct(private EntityManager $entityManager) {}

    public function beforeRemove(Entity $entity, RemoveOptions $options): void
    {
        if ($this->entityManager->getRDBRepository('Initiative')->where(['stageId' => $entity->getId()])->findOne()) {
            throw ValidationError::badRequest('stageInUse', 'This stage has initiatives. Deactivate it instead of deleting it.');
        }
    }
}

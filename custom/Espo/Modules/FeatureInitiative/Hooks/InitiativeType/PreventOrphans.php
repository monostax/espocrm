<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureInitiative\Hooks\InitiativeType;

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
        foreach (['InitiativeStage', 'Initiative'] as $type) {
            if ($this->entityManager->getRDBRepository($type)->where(['initiativeTypeId' => $entity->getId()])->findOne()) {
                throw ValidationError::badRequest('initiativeTypeInUse', 'This initiative type has stages or initiatives. Deactivate it instead of deleting it.');
            }
        }
    }
}

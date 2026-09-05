<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureSimpleJourney\Hooks\SimpleJourney;

use Espo\Modules\FeatureSimpleJourney\Services\ValidationError;
use Espo\Core\Hook\Hook\BeforeRemove;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\RemoveOptions;

class PreventOrphans implements BeforeRemove
{
    public function __construct(private EntityManager $entityManager) {}

    public function beforeRemove(Entity $entity, RemoveOptions $options): void
    {
        foreach (['SimpleJourneyStage', 'SimpleJourneyRecord'] as $type) {
            if ($this->entityManager->getRDBRepository($type)->where(['journeyId' => $entity->getId()])->findOne()) {
                throw ValidationError::badRequest('journeyInUse', 'This journey has stages or records. Deactivate it instead of deleting it.');
            }
        }
    }
}

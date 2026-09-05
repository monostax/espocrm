<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureSimpleJourney\Hooks\SimpleJourneyStage;

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
        if ($this->entityManager->getRDBRepository('SimpleJourneyRecord')->where(['stageId' => $entity->getId()])->findOne()) {
            throw ValidationError::badRequest('stageInUse', 'This stage has records. Deactivate it instead of deleting it.');
        }
    }
}

<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureJourney\Hooks\JourneyRecord;

use Espo\Core\Hook\Hook\BeforeSave;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;

/**
 * Keeps JourneyRecord.name = target entity display name (for lists / defaults).
 *
 * @implements BeforeSave<Entity>
 */
class SyncNameFromTarget implements BeforeSave
{
    public static int $order = 8;

    public function __construct(
        private EntityManager $entityManager,
    ) {}

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        $targetType = $entity->get('targetType');
        $targetId = $entity->get('targetId');

        if (!is_string($targetType) || $targetType === '' || !is_string($targetId) || $targetId === '') {
            return;
        }

        $needSync =
            $entity->isNew() ||
            $entity->isAttributeChanged('targetId') ||
            $entity->isAttributeChanged('targetType') ||
            !$entity->get('name');

        if (!$needSync) {
            return;
        }

        if (!$this->entityManager->hasRepository($targetType)) {
            return;
        }

        $target = $this->entityManager->getEntityById($targetType, $targetId);

        if (!$target) {
            if (!$entity->get('name')) {
                $entity->set('name', $targetType . ' ' . $targetId);
            }

            return;
        }

        $name = $target->get('name');

        if (!is_string($name) || trim($name) === '') {
            $name = $targetType . ' ' . $targetId;
        }

        $entity->set('name', $name);

        if (!$entity->get('targetName')) {
            $entity->set('targetName', $name);
        }
    }
}

<?php

namespace Espo\Modules\Global\Hooks\Team;

use Espo\Core\Hook\Hook\AfterSave;
use Espo\Core\Utils\Metadata;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;

class GlobalSharing implements AfterSave
{
    public function __construct(
        private EntityManager $entityManager,
        private Metadata $metadata 
    ) {}

    public function afterSave(Entity $entity, SaveOptions $options): void
    {
        if (!$entity->isNew()) {
            return;
        }

        $entityTypes = $this->metadata->get(['entityDefs'], []);

        foreach ($entityTypes as $entityType => $entityDef) {
            // Check if entity has the isGloballyShared field
            if (empty($entityDef['fields']['isGloballyShared']) || $entityDef['fields']['isGloballyShared']['type'] !== 'bool') {
                continue;
            }

            // Check if entity has a teams link
            if (empty($entityDef['links']['teams'])) {
                continue;
            }

            $this->shareGlobally($entity, $entityType);
        }
    }

    private function shareGlobally(Entity $team, string $entityType): void
    {
        $entitiesToShare = $this->entityManager->getRepository($entityType)
            ->where([
                'isGloballyShared' => true
            ])
            ->find();

        foreach ($entitiesToShare as $entityToShare) {
            $this->entityManager->getRepository($entityType)->relate($entityToShare, 'teams', $team);
        }
    }
}

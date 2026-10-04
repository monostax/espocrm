<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureInitiative\Hooks\InitiativeStage;

use Espo\Core\Hook\Hook\AfterSave;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;

class SyncInitiativeStatus implements AfterSave
{
    public function __construct(private EntityManager $entityManager) {}

    public function afterSave(Entity $entity, SaveOptions $options): void
    {
        if (!$entity->isAttributeChanged('category')) {
            return;
        }

        // Configuration changes refresh the derived value without editing each work item.
        $query = $this->entityManager->getQueryBuilder()->update()
            ->in('Initiative')
            ->set(['status' => $entity->get('category')])
            ->where(['stageId' => $entity->getId(), 'deleted' => false])
            ->build();

        $this->entityManager->getQueryExecutor()->execute($query);
    }
}

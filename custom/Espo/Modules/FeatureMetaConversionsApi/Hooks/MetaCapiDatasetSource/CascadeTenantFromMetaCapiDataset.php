<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureMetaConversionsApi\Hooks\MetaCapiDatasetSource;

use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Modules\FeatureMetaConversionsApi\Entities\MetaCapiDataset;
use Espo\Modules\FeatureMetaConversionsApi\Entities\MetaCapiDatasetSource;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;

/**
 * Cascades tenant from the parent MetaCapiDataset to this source binding.
 *
 * @implements BeforeSave<MetaCapiDatasetSource>
 */
class CascadeTenantFromMetaCapiDataset implements BeforeSave
{
    public static int $order = 1;

    public function __construct(
        private EntityManager $entityManager,
    ) {}

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        if (!$entity instanceof MetaCapiDatasetSource) {
            return;
        }

        if ($options->get('silent')) {
            return;
        }

        $datasetId = $entity->get('metaCapiDatasetId');

        if (!$datasetId) {
            return;
        }

        $dataset = $this->entityManager->getEntityById(MetaCapiDataset::ENTITY_TYPE, $datasetId);

        if (!$dataset) {
            return;
        }

        $tenantId = $dataset->get('tenantId');

        if ($tenantId) {
            $entity->set('tenantId', $tenantId);
        }
    }
}

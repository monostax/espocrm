<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureMetaConversionsApi\Hooks\MetaCapiEventLog;

use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Modules\FeatureMetaConversionsApi\Entities\MetaCapiDataset;
use Espo\Modules\FeatureMetaConversionsApi\Entities\MetaCapiEventLog;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;

/**
 * Cascades tenant from the parent MetaCapiDataset to this event log row.
 *
 * Denormalizes MetaCapiDataset.tenant onto MetaCapiEventLog so per-tenant
 * reports and ACL filters can index/group directly on the event log table
 * without joining through the dataset.
 *
 * Only sets tenantId on insert / when the field is empty, so an explicit
 * assignment is never overwritten.
 *
 * @implements BeforeSave<MetaCapiEventLog>
 */
class CascadeTenantFromMetaCapiDataset implements BeforeSave
{
    public static int $order = 1;

    public function __construct(
        private EntityManager $entityManager,
    ) {}

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        if (!$entity instanceof MetaCapiEventLog) {
            return;
        }

        if ($options->get('silent')) {
            return;
        }

        if ($entity->get('tenantId')) {
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

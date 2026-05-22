<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureMetaConversionsApi\Services;

use Espo\Core\Utils\Log;
use Espo\Modules\Crm\Entities\Opportunity;
use Espo\Modules\FeatureMetaConversionsApi\Entities\MetaCapiDataset;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Resolves which MetaCapiDataset should receive an event for a given source entity.
 *
 * Resolution order:
 *   1. Opportunity.funnel.metaCapiDataset (if funnel.metaCapiEnabled=true)
 *   2. Any MetaCapiDataset with isDefault=true AND isActive=true
 *   3. null  (caller must skip)
 */
class DatasetResolver
{
    public function __construct(
        private EntityManager $entityManager,
        private Log $log,
    ) {}

    public function resolveForEntity(Entity $entity): ?MetaCapiDataset
    {
        if ($entity instanceof Opportunity) {
            $resolved = $this->resolveFromOpportunity($entity);

            if ($resolved) {
                return $resolved;
            }
        }

        return $this->getDefault();
    }

    public function resolveFromOpportunity(Opportunity $opportunity): ?MetaCapiDataset
    {
        $funnelId = $opportunity->get('funnelId');

        if (!$funnelId) {
            return null;
        }

        $funnel = $this->entityManager->getEntityById('Funnel', $funnelId);

        if (!$funnel) {
            return null;
        }

        if (!$funnel->get('metaCapiEnabled')) {
            return null;
        }

        $datasetId = $funnel->get('metaCapiDatasetId');

        if (!$datasetId) {
            return null;
        }

        $dataset = $this->entityManager->getEntityById('MetaCapiDataset', $datasetId);

        if (!$dataset instanceof MetaCapiDataset) {
            return null;
        }

        if (!$dataset->get('isActive')) {
            return null;
        }

        return $dataset;
    }

    public function getDefault(): ?MetaCapiDataset
    {
        $dataset = $this->entityManager
            ->getRDBRepository('MetaCapiDataset')
            ->where([
                'isDefault' => true,
                'isActive'  => true,
                'deleted'   => false,
            ])
            ->findOne();

        return $dataset instanceof MetaCapiDataset ? $dataset : null;
    }
}

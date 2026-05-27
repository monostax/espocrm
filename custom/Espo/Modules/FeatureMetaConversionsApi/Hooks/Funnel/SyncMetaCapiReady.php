<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureMetaConversionsApi\Hooks\Funnel;

use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Core\Utils\Log;
use Espo\Modules\FeatureMetaConversionsApi\Entities\MetaCapiDataset;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;
use Throwable;

/**
 * Maintains the denormalised `metaCapiReady` flag on Funnel — the single
 * source of truth for whether this funnel's stages should expose the
 * "Meta Conversions API" panel on their detail layout.
 *
 * Definition:
 *   metaCapiReady := metaCapiEnabled
 *                  AND metaCapiDatasetId IS NOT NULL
 *                  AND dataset.isActive = true
 *                  AND dataset NOT deleted
 *
 * Mirrors the runtime guard in
 * Espo\Modules\FeatureMetaConversionsApi\Services\DatasetResolver::resolveFromOpportunity
 * so the UI surfaces exactly the configuration that would fire at runtime.
 *
 * Triggered before every Funnel save. Only computes when one of the input
 * fields could have changed (or on insert), so steady-state saves are
 * effectively free.
 *
 * OpportunityStage rows read this via the foreign field
 * `funnelMetaCapiReady` — no per-stage duplication is needed and there
 * is no "new stage created after the funnel was wired" stale-flag bug.
 *
 * @implements BeforeSave<Entity>
 */
class SyncMetaCapiReady implements BeforeSave
{
    public static int $order = 25;

    public function __construct(
        private EntityManager $entityManager,
        private Log $log,
    ) {}

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        if ($entity->getEntityType() !== 'Funnel') {
            return;
        }

        if ($options->get('silent')) {
            return;
        }

        $relevantChanged = $entity->isNew()
            || $entity->isAttributeChanged('metaCapiEnabled')
            || $entity->isAttributeChanged('metaCapiDatasetId');

        if (!$relevantChanged) {
            return;
        }

        $value = $this->computeReady($entity);

        if ((bool) $entity->get('metaCapiReady') === $value) {
            return;
        }

        $entity->set('metaCapiReady', $value);
    }

    private function computeReady(Entity $funnel): bool
    {
        if (!$funnel->get('metaCapiEnabled')) {
            return false;
        }

        $datasetId = (string) ($funnel->get('metaCapiDatasetId') ?? '');

        if ($datasetId === '') {
            return false;
        }

        try {
            $dataset = $this->entityManager
                ->getEntityById(MetaCapiDataset::ENTITY_TYPE, $datasetId);
        } catch (Throwable $e) {
            $this->log->warning(
                'MetaCapi SyncMetaCapiReady: dataset lookup failed for funnel '
                . ($funnel->getId() ?? '(new)') . ': ' . $e->getMessage()
            );

            return false;
        }

        if (!$dataset instanceof MetaCapiDataset) {
            return false;
        }

        return (bool) $dataset->get('isActive');
    }
}

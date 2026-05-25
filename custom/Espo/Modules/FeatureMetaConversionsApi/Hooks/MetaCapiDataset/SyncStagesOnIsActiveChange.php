<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureMetaConversionsApi\Hooks\MetaCapiDataset;

use Espo\Core\Hook\Hook\AfterSave;
use Espo\Core\Utils\Log;
use Espo\Modules\FeatureMetaConversionsApi\Entities\MetaCapiDataset;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;
use Throwable;

/**
 * When a MetaCapiDataset's `isActive` flag toggles, propagate the change to
 * the OpportunityStage.metaCapiActive flag on every stage of every funnel
 * that links to this dataset.
 *
 * Together with Funnel/AfterSave/SyncStageCapiActive, this keeps the
 * denormalised flag aligned with the live wiring state without requiring
 * every consumer to re-derive it on read.
 *
 * Cheap in steady-state: only fires when `isActive` actually changes.
 *
 * @implements AfterSave<MetaCapiDataset>
 */
class SyncStagesOnIsActiveChange implements AfterSave
{
    public static int $order = 25;

    public function __construct(
        private EntityManager $entityManager,
        private Log $log,
    ) {}

    public function afterSave(Entity $entity, SaveOptions $options): void
    {
        if (!$entity instanceof MetaCapiDataset) {
            return;
        }

        if ($options->get('silent')) {
            return;
        }

        if (!$entity->isAttributeChanged('isActive') && !$entity->isNew()) {
            return;
        }

        $newActive = (bool) $entity->get('isActive');

        $this->syncStagesOfReferencingFunnels($entity->getId(), $newActive);
    }

    /**
     * Recompute and stamp metaCapiActive on every stage of every funnel
     * that links to this dataset. We can short-circuit to `$newActive` only
     * when the funnel itself also has `metaCapiEnabled=true` — otherwise
     * the stage should be false regardless of dataset state.
     */
    private function syncStagesOfReferencingFunnels(?string $datasetId, bool $datasetActive): void
    {
        if (!$datasetId) {
            return;
        }

        try {
            $funnels = $this->entityManager
                ->getRDBRepository('Funnel')
                ->where(['metaCapiDatasetId' => $datasetId, 'deleted' => false])
                ->find();

            foreach ($funnels as $funnel) {
                $shouldBeActive = $datasetActive && (bool) $funnel->get('metaCapiEnabled');

                $stages = $this->entityManager
                    ->getRDBRepository('OpportunityStage')
                    ->where(['funnelId' => $funnel->getId(), 'deleted' => false])
                    ->find();

                foreach ($stages as $stage) {
                    if ((bool) $stage->get('metaCapiActive') === $shouldBeActive) {
                        continue;
                    }

                    $stage->set('metaCapiActive', $shouldBeActive);

                    $this->entityManager->saveEntity(
                        $stage,
                        ['skipHooks' => true, 'silent' => true],
                    );
                }
            }
        } catch (Throwable $e) {
            $this->log->warning(
                'MetaCapi SyncStagesOnIsActiveChange: failed for dataset ' . $datasetId . ': ' . $e->getMessage()
            );
        }
    }
}

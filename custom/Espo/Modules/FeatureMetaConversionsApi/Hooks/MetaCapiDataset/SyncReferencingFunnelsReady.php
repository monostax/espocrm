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
 * When MetaCapiDataset.isActive changes, recompute `metaCapiReady` on
 * every Funnel that references this dataset.
 *
 * Companion to Funnel/BeforeSave/SyncMetaCapiReady which handles the
 * "funnel-side input changed" half. Together they keep
 * `Funnel.metaCapiReady` aligned with the live wiring state without
 * requiring readers to re-derive it on every query.
 *
 * OpportunityStage detail views read this value through the foreign
 * field `funnelMetaCapiReady` — no per-stage duplication, no per-stage
 * hooks, no back-fill needed for newly-created stages.
 *
 * Cheap in steady-state: only iterates when `isActive` actually
 * transitions (or on insert, which is rare and bounded by tenant).
 *
 * @implements AfterSave<MetaCapiDataset>
 */
class SyncReferencingFunnelsReady implements AfterSave
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

        try {
            $funnels = $this->entityManager
                ->getRDBRepository('Funnel')
                ->where(['metaCapiDatasetId' => $entity->getId(), 'deleted' => false])
                ->find();

            foreach ($funnels as $funnel) {
                $shouldBeReady = $newActive && (bool) $funnel->get('metaCapiEnabled');

                if ((bool) $funnel->get('metaCapiReady') === $shouldBeReady) {
                    continue;
                }

                $funnel->set('metaCapiReady', $shouldBeReady);

                // skipHooks + silent: this is system-derived state, not a
                // user-meaningful funnel edit. Avoid Stream notifications
                // and avoid re-entering SyncMetaCapiReady (which would
                // produce the same value anyway).
                $this->entityManager->saveEntity(
                    $funnel,
                    ['skipHooks' => true, 'silent' => true],
                );
            }
        } catch (Throwable $e) {
            $this->log->warning(
                'MetaCapi SyncReferencingFunnelsReady: failed for dataset '
                . ($entity->getId() ?? '(new)') . ': ' . $e->getMessage()
            );
        }
    }
}

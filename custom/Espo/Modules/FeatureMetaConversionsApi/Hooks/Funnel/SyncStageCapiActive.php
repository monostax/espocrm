<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureMetaConversionsApi\Hooks\Funnel;

use Espo\Core\Hook\Hook\AfterSave;
use Espo\Core\Utils\Log;
use Espo\Modules\FeatureMetaConversionsApi\Entities\MetaCapiDataset;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;
use Throwable;

/**
 * Denormalises the funnel's CAPI wiring state onto each child
 * OpportunityStage's `metaCapiActive` flag.
 *
 * The flag is true iff:
 *   - Funnel.metaCapiEnabled = true, AND
 *   - Funnel.metaCapiDatasetId is set, AND
 *   - the linked MetaCapiDataset.isActive = true (and not deleted).
 *
 * Mirrors the runtime guard in
 * Espo\Modules\FeatureMetaConversionsApi\Services\DatasetResolver::resolveFromOpportunity
 * (lines 77-95) so the UI surfaces exactly the configuration that would
 * actually fire at runtime.
 *
 * Triggered after every Funnel save. Only iterates child stages when one
 * of the relevant fields changed (or on insert), so steady-state saves are
 * cheap. Stage writes use `skipHooks => true, silent => true` because the
 * flag is read-only system state — no Stream notification needed.
 *
 * @implements AfterSave<Entity>
 */
class SyncStageCapiActive implements AfterSave
{
    public static int $order = 25;

    public function __construct(
        private EntityManager $entityManager,
        private Log $log,
    ) {}

    public function afterSave(Entity $entity, SaveOptions $options): void
    {
        if ($entity->getEntityType() !== 'Funnel') {
            return;
        }

        if ($options->get('silent')) {
            return;
        }

        $relevantChanged =
            $entity->isNew() ||
            $entity->isAttributeChanged('metaCapiEnabled') ||
            $entity->isAttributeChanged('metaCapiDatasetId');

        if (!$relevantChanged) {
            return;
        }

        $shouldBeActive = $this->computeActiveForFunnel($entity);

        $this->syncStagesOfFunnel((string) $entity->getId(), $shouldBeActive);
    }

    /**
     * Compute whether the given Funnel currently meets all conditions for
     * its stages to be considered CAPI-active.
     */
    private function computeActiveForFunnel(Entity $funnel): bool
    {
        if (!$funnel->get('metaCapiEnabled')) {
            return false;
        }

        $datasetId = $funnel->get('metaCapiDatasetId');

        if (!$datasetId) {
            return false;
        }

        $dataset = $this->entityManager
            ->getEntityById(MetaCapiDataset::ENTITY_TYPE, $datasetId);

        if (!$dataset instanceof MetaCapiDataset) {
            return false;
        }

        if (!$dataset->get('isActive')) {
            return false;
        }

        return true;
    }

    /**
     * Stamp `metaCapiActive = $value` on every non-deleted OpportunityStage
     * belonging to the given funnel. Skips rows already at the target value.
     */
    private function syncStagesOfFunnel(string $funnelId, bool $value): void
    {
        try {
            $stages = $this->entityManager
                ->getRDBRepository('OpportunityStage')
                ->where(['funnelId' => $funnelId, 'deleted' => false])
                ->find();

            foreach ($stages as $stage) {
                if ((bool) $stage->get('metaCapiActive') === $value) {
                    continue;
                }

                $stage->set('metaCapiActive', $value);

                $this->entityManager->saveEntity(
                    $stage,
                    ['skipHooks' => true, 'silent' => true],
                );
            }
        } catch (Throwable $e) {
            $this->log->warning(
                'MetaCapi SyncStageCapiActive: failed for funnel ' . $funnelId . ': ' . $e->getMessage()
            );
        }
    }
}

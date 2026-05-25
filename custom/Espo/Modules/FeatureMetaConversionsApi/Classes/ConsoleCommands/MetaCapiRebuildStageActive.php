<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureMetaConversionsApi\Classes\ConsoleCommands;

use Espo\Core\Console\Command;
use Espo\Core\Console\Command\Params;
use Espo\Core\Console\IO;
use Espo\Modules\FeatureMetaConversionsApi\Entities\MetaCapiDataset;
use Espo\ORM\EntityManager;
use Throwable;

/**
 * One-shot back-fill of OpportunityStage.metaCapiActive for the whole DB.
 *
 * Run once after deploying the denormalisation hooks; existing rows still
 * have the default value (false) until something touches their parent
 * funnel or dataset. This command recomputes the flag for every stage in
 * every funnel.
 *
 * Usage:
 *   php command.php meta-capi:rebuild-stage-active
 */
class MetaCapiRebuildStageActive implements Command
{
    public function __construct(
        private EntityManager $entityManager,
    ) {}

    public function run(Params $params, IO $io): void
    {
        $funnels = $this->entityManager
            ->getRDBRepository('Funnel')
            ->where(['deleted' => false])
            ->find();

        $stagesTouched   = 0;
        $stagesUnchanged = 0;
        $funnelsScanned  = 0;

        try {
            foreach ($funnels as $funnel) {
                $funnelsScanned++;

                $shouldBeActive = $this->computeActiveForFunnel($funnel);

                $stages = $this->entityManager
                    ->getRDBRepository('OpportunityStage')
                    ->where(['funnelId' => $funnel->getId(), 'deleted' => false])
                    ->find();

                foreach ($stages as $stage) {
                    if ((bool) $stage->get('metaCapiActive') === $shouldBeActive) {
                        $stagesUnchanged++;
                        continue;
                    }

                    $stage->set('metaCapiActive', $shouldBeActive);

                    $this->entityManager->saveEntity(
                        $stage,
                        ['skipHooks' => true, 'silent' => true],
                    );

                    $stagesTouched++;
                }
            }
        } catch (Throwable $e) {
            $io->setExitStatus(1);
            $io->writeLine('Error: ' . $e->getMessage());

            return;
        }

        $io->writeLine(sprintf(
            'Done. funnels=%d  stagesTouched=%d  stagesUnchanged=%d',
            $funnelsScanned,
            $stagesTouched,
            $stagesUnchanged,
        ));
    }

    private function computeActiveForFunnel(object $funnel): bool
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

        return (bool) $dataset->get('isActive');
    }
}

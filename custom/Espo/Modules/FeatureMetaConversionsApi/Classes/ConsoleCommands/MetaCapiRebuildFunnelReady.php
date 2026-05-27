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
 * One-shot back-fill of Funnel.metaCapiReady for every funnel in the DB.
 *
 * The flag is normally maintained by the Funnel/BeforeSave and
 * MetaCapiDataset/AfterSave hooks. Existing funnels created before those
 * hooks shipped (or before a column was added) won't have the value set
 * until something touches them. Run this once after deploying.
 *
 * OpportunityStage rows do NOT need back-fill — they read the value
 * through the foreign field `funnelMetaCapiReady` on each query.
 *
 * Usage:
 *   php command.php meta-capi:rebuild-funnel-ready
 */
class MetaCapiRebuildFunnelReady implements Command
{
    public function __construct(
        private EntityManager $entityManager,
    ) {}

    public function run(Params $params, IO $io): void
    {
        $funnelsScanned = 0;
        $funnelsTouched = 0;
        $funnelsUnchanged = 0;

        try {
            $funnels = $this->entityManager
                ->getRDBRepository('Funnel')
                ->where(['deleted' => false])
                ->find();

            foreach ($funnels as $funnel) {
                $funnelsScanned++;

                $shouldBeReady = $this->computeReady($funnel);

                if ((bool) $funnel->get('metaCapiReady') === $shouldBeReady) {
                    $funnelsUnchanged++;
                    continue;
                }

                $funnel->set('metaCapiReady', $shouldBeReady);

                $this->entityManager->saveEntity(
                    $funnel,
                    ['skipHooks' => true, 'silent' => true],
                );

                $funnelsTouched++;
            }
        } catch (Throwable $e) {
            $io->setExitStatus(1);
            $io->writeLine('Error: ' . $e->getMessage());

            return;
        }

        $io->writeLine(sprintf(
            'Done. scanned=%d  touched=%d  unchanged=%d',
            $funnelsScanned,
            $funnelsTouched,
            $funnelsUnchanged,
        ));
    }

    private function computeReady(object $funnel): bool
    {
        if (!$funnel->get('metaCapiEnabled')) {
            return false;
        }

        $datasetId = (string) ($funnel->get('metaCapiDatasetId') ?? '');

        if ($datasetId === '') {
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

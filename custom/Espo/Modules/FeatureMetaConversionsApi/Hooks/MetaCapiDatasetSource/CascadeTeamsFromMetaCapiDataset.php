<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureMetaConversionsApi\Hooks\MetaCapiDatasetSource;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Modules\FeatureMetaConversionsApi\Entities\MetaCapiDataset;
use Espo\Modules\FeatureMetaConversionsApi\Entities\MetaCapiDatasetSource;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;

/**
 * Cascades teams from the parent MetaCapiDataset to this source binding.
 *
 * `teams` is readOnly on MetaCapiDatasetSource (not user-editable), so this is
 * the sole source of its team scope. It is ACL-critical: a teamless source row
 * would be invisible to team-scoped reads and would later create teamless
 * Opportunities. The parent dataset guarantees >=1 team (teams is required on
 * MetaCapiDataset), so an empty result here means a misconfiguration — fail
 * loudly rather than persisting an unscoped row.
 *
 * @implements BeforeSave<MetaCapiDatasetSource>
 */
class CascadeTeamsFromMetaCapiDataset implements BeforeSave
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

        try {
            $teamsIds = $dataset->getLinkMultipleIdList('teams');
        } catch (\Throwable) {
            $teamsIds = [];
        }

        if (empty($teamsIds)) {
            throw new BadRequest(
                'The selected dataset has no teams; assign teams on the dataset first so this source can inherit its team scope.'
            );
        }

        $entity->set('teamsIds', $teamsIds);
    }
}

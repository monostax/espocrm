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
 * Cascades teams from the parent MetaCapiDataset to this event log row.
 *
 * Ensures multi-tenant ACL isolation: every event log inherits the team
 * set of the dataset that produced it. Always runs (not just on insert)
 * so re-saves with a different parent stay consistent.
 *
 * Mirrors Espo\Modules\Chatwoot\Hooks\ChatwootAccountWebhook\CascadeTeamsFromAccount.
 *
 * @implements BeforeSave<MetaCapiEventLog>
 */
class CascadeTeamsFromMetaCapiDataset implements BeforeSave
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
            return;
        }

        if (!empty($teamsIds)) {
            $entity->set('teamsIds', $teamsIds);
        }
    }
}

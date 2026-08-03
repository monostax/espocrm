<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureJourney\Hooks\JourneyRecordLog;

use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Modules\FeatureJourney\Entities\Journey;
use Espo\Modules\FeatureJourney\Entities\JourneyRecord;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;

/**
 * Cascades tenantId + teamsIds onto JourneyRecordLog from parent record, then
 * Journey (same grain as JourneyRecord/CascadeTenantTeamsFromJourney).
 *
 * @implements BeforeSave<Entity>
 */
class CascadeTenantTeamsFromRecord implements BeforeSave
{
    public static int $order = 5;

    public function __construct(
        private EntityManager $entityManager,
    ) {}

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        if ($options->get('silent') && $entity->get('tenantId')) {
            return;
        }

        $parent = $this->resolveParent($entity);
        if (!$parent) {
            return;
        }

        if (!$entity->get('tenantId') && $parent->get('tenantId')) {
            $entity->set('tenantId', $parent->get('tenantId'));
        }

        try {
            $teamsIds = $parent->getLinkMultipleIdList('teams') ?: [];
        } catch (\Throwable) {
            $teamsIds = $parent->get('teamsIds') ?: [];
        }

        if (!empty($teamsIds)) {
            $entity->set('teamsIds', $teamsIds);
        }
    }

    private function resolveParent(Entity $entity): ?Entity
    {
        $recordId = $entity->get('recordId');
        if ($recordId) {
            $record = $this->entityManager->getEntityById(
                JourneyRecord::ENTITY_TYPE,
                (string) $recordId,
            );
            if ($record) {
                return $record;
            }
        }

        $journeyId = $entity->get('journeyId');
        if (!$journeyId) {
            return null;
        }

        return $this->entityManager->getEntityById(
            Journey::ENTITY_TYPE,
            (string) $journeyId,
        );
    }
}

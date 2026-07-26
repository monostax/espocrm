<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureJourney\Hooks\JourneyRecord;

use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Modules\FeatureJourney\Entities\Journey;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;

/** @implements BeforeSave<Entity> */
class CascadeTenantTeamsFromJourney implements BeforeSave
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

        $journeyId = $entity->get('journeyId');
        if (!$journeyId) {
            return;
        }

        $journey = $this->entityManager->getEntityById(Journey::ENTITY_TYPE, (string) $journeyId);
        if (!$journey) {
            return;
        }

        if (!$entity->get('tenantId') && $journey->get('tenantId')) {
            $entity->set('tenantId', $journey->get('tenantId'));
        }

        try {
            $teamsIds = $journey->getLinkMultipleIdList('teams') ?: [];
        } catch (\Throwable) {
            $teamsIds = $journey->get('teamsIds') ?: [];
        }

        if (!empty($teamsIds)) {
            $entity->set('teamsIds', $teamsIds);
        }
    }
}

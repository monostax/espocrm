<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureTrackingEvent\Hooks\TrackingEvent;

use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Modules\FeatureTrackingEvent\Entities\TrackingEvent;
use Espo\Modules\FeatureTrackingEvent\Entities\TrackingEventType;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;

/**
 * Cascades teams from the parent TrackingEventType to this event row.
 *
 * Mirrors Espo\Modules\FeatureMetaLeadAds\Hooks\MetaLeadgenAnswer\CascadeTeamsFromEvent.
 *
 * @implements BeforeSave<TrackingEvent>
 */
class CascadeTeamsFromEventType implements BeforeSave
{
    public static int $order = 1;

    public function __construct(
        private EntityManager $entityManager,
    ) {}

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        if (!$entity instanceof TrackingEvent) {
            return;
        }

        if ($options->get('silent')) {
            return;
        }

        $typeId = $entity->get('trackingEventTypeId');

        if (!$typeId) {
            return;
        }

        $type = $this->entityManager->getEntityById(TrackingEventType::ENTITY_TYPE, $typeId);

        if (!$type) {
            return;
        }

        try {
            $teamsIds = $type->getLinkMultipleIdList('teams');
        } catch (\Throwable) {
            return;
        }

        if (!empty($teamsIds)) {
            $entity->set('teamsIds', $teamsIds);
        }
    }
}

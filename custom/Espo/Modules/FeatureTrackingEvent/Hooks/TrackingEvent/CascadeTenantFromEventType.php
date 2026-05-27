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
 * Cascades tenant from the parent TrackingEventType to this event row.
 *
 * Mirrors Espo\Modules\FeatureMetaLeadAds\Hooks\MetaLeadgenAnswer\CascadeTenantFromEvent.
 *
 * @implements BeforeSave<TrackingEvent>
 */
class CascadeTenantFromEventType implements BeforeSave
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

        if ($entity->get('tenantId')) {
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

        $tenantId = $type->get('tenantId');

        if ($tenantId) {
            $entity->set('tenantId', $tenantId);
        }
    }
}

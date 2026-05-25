<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureMetaLeadAds\Hooks\MetaLeadgenAnswer;

use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Modules\FeatureMetaLeadAds\Entities\MetaLeadgenAnswer;
use Espo\Modules\FeatureMetaLeadAds\Entities\MetaLeadgenEvent;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;

/**
 * Cascades tenant from the parent MetaLeadgenEvent to this answer row.
 *
 * Mirrors MetaLeadForm/CascadeTenantFromPage.
 *
 * @implements BeforeSave<MetaLeadgenAnswer>
 */
class CascadeTenantFromEvent implements BeforeSave
{
    public static int $order = 1;

    public function __construct(
        private EntityManager $entityManager,
    ) {}

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        if (!$entity instanceof MetaLeadgenAnswer) {
            return;
        }

        if ($options->get('silent')) {
            return;
        }

        if ($entity->get('tenantId')) {
            return;
        }

        $eventId = $entity->get('eventId');

        if (!$eventId) {
            return;
        }

        $event = $this->entityManager->getEntityById(MetaLeadgenEvent::ENTITY_TYPE, $eventId);

        if (!$event) {
            return;
        }

        $tenantId = $event->get('tenantId');

        if ($tenantId) {
            $entity->set('tenantId', $tenantId);
        }
    }
}

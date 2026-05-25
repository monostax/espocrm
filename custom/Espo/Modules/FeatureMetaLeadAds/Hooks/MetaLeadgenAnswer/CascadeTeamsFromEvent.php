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
 * Cascades teams from the parent MetaLeadgenEvent to this answer row.
 *
 * Mirrors MetaLeadForm/CascadeTeamsFromPage.
 *
 * @implements BeforeSave<MetaLeadgenAnswer>
 */
class CascadeTeamsFromEvent implements BeforeSave
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

        $eventId = $entity->get('eventId');

        if (!$eventId) {
            return;
        }

        $event = $this->entityManager->getEntityById(MetaLeadgenEvent::ENTITY_TYPE, $eventId);

        if (!$event) {
            return;
        }

        try {
            $teamsIds = $event->getLinkMultipleIdList('teams');
        } catch (\Throwable) {
            return;
        }

        if (!empty($teamsIds)) {
            $entity->set('teamsIds', $teamsIds);
        }
    }
}

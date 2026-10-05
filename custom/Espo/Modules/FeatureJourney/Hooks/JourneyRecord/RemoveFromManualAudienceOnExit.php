<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureJourney\Hooks\JourneyRecord;

use Espo\Core\Hook\Hook\AfterSave;
use Espo\Modules\FeatureJourney\Entities\Journey;
use Espo\Modules\FeatureJourney\Entities\JourneyRecord;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;

/** @implements AfterSave<Entity> */
class RemoveFromManualAudienceOnExit implements AfterSave
{
    public static int $order = 10;

    public function __construct(
        private EntityManager $entityManager,
    ) {}

    public function afterSave(Entity $entity, SaveOptions $options): void
    {
        // All exit paths save silently with skipJourneyDispatch. Audience cleanup
        // must still run for manual exits, Exit stages, and max-duration exits.
        if ($entity->get('status') !== JourneyRecord::STATUS_EXITED ||
            !$entity->isAttributeChanged('status')) {
            return;
        }

        $relation = match ($entity->get('targetType')) {
            'Opportunity' => 'manualOpportunities',
            'Contact' => 'manualContacts',
            default => null,
        };
        $journeyId = $entity->get('journeyId');
        $targetId = $entity->get('targetId');

        if ($relation === null || !$journeyId || !$targetId) {
            return;
        }

        $journey = $this->entityManager->getEntityById(Journey::ENTITY_TYPE, (string) $journeyId);
        if (!$journey) {
            return;
        }

        // Only unlink this journey's explicit audience; TargetLists may be shared.
        $this->entityManager
            ->getRDBRepository(Journey::ENTITY_TYPE)
            ->getRelation($journey, $relation)
            ->unrelateById((string) $targetId);
    }
}

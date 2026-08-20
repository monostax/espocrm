<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureGoogleAdsConversions\Hooks\GoogleAdsConversionMapping;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Modules\FeatureGoogleAdsConversions\Entities\GoogleAdsConversionMapping;
use Espo\Modules\FeatureGoogleAdsConversions\Entities\GoogleAdsDestination;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;
use Throwable;

/** @implements BeforeSave<Entity> */
class CascadeTeamsFromDestination implements BeforeSave
{
    public static int $order = 1;

    public function __construct(private EntityManager $entityManager) {}

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        if (!$entity instanceof GoogleAdsConversionMapping) {
            return;
        }

        $destination = $this->destination($entity);

        if (!$destination) {
            return;
        }

        try {
            $teamIds = array_values($destination->getLinkMultipleIdList('teams'));
        } catch (Throwable) {
            $teamIds = [];
        }

        if ($teamIds === []) {
            throw new BadRequest('The selected Google Ads destination has no teams to inherit.');
        }

        $entity->set('teamsIds', $teamIds);
    }

    private function destination(GoogleAdsConversionMapping $entity): ?GoogleAdsDestination
    {
        $id = $entity->get('destinationId');
        $destination = $id
            ? $this->entityManager->getEntityById(GoogleAdsDestination::ENTITY_TYPE, (string) $id)
            : null;

        return $destination instanceof GoogleAdsDestination ? $destination : null;
    }
}

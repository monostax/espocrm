<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureGoogleAdsConversions\Hooks\GoogleAdsConversionMapping;

use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Modules\FeatureGoogleAdsConversions\Entities\GoogleAdsConversionMapping;
use Espo\Modules\FeatureGoogleAdsConversions\Entities\GoogleAdsDestination;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;

/** @implements BeforeSave<Entity> */
class CascadeTenantFromDestination implements BeforeSave
{
    public static int $order = 1;

    public function __construct(private EntityManager $entityManager) {}

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        if (!$entity instanceof GoogleAdsConversionMapping) {
            return;
        }

        $id = $entity->get('destinationId');
        $destination = $id
            ? $this->entityManager->getEntityById(GoogleAdsDestination::ENTITY_TYPE, (string) $id)
            : null;

        if ($destination instanceof GoogleAdsDestination && $destination->get('tenantId')) {
            $entity->set('tenantId', $destination->get('tenantId'));
        }
    }
}

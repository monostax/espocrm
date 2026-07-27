<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureJourney\Hooks\Journey;

use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Modules\FeatureJourney\Entities\Journey as JourneyEntity;
use Espo\Modules\Global\Services\RunAsUserAccess;
use Espo\ORM\Entity;
use Espo\ORM\Repository\Option\SaveOptions;

/** @implements BeforeSave<JourneyEntity> */
class ValidateRunAsUser implements BeforeSave
{
    /** After AssignTenantFromTeam. */
    public static int $order = 15;

    public function __construct(
        private RunAsUserAccess $runAsUserAccess,
    ) {}

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        if (!$entity instanceof JourneyEntity || $options->get('silent')) {
            return;
        }

        if (!$entity->isNew() && !$entity->isAttributeChanged('runAsUserId')) {
            return;
        }

        $runAsUserId = $entity->get('runAsUserId');
        $runAsUserId = is_string($runAsUserId) && $runAsUserId !== '' ? $runAsUserId : null;

        $tenantId = $entity->get('tenantId');
        $tenantId = is_string($tenantId) && $tenantId !== '' ? $tenantId : null;

        $this->runAsUserAccess->assertCanSet($runAsUserId, $tenantId);
    }
}

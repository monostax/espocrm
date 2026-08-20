<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureGoogleAdsConversions\Hooks\GoogleAdsDestination;

use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Modules\FeatureGoogleAdsConversions\Entities\GoogleAdsDestination;
use Espo\Modules\Global\Services\TeamTenantAccess;
use Espo\ORM\Entity;
use Espo\ORM\Repository\Option\SaveOptions;

/** @implements BeforeSave<Entity> */
class AssignTenantFromTeam implements BeforeSave
{
    public static int $order = 9;

    public function __construct(
        private TeamTenantAccess $teamTenantAccess,
    ) {}

    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        if (
            !$entity instanceof GoogleAdsDestination ||
            $options->get('silent') ||
            $entity->get('tenantId')
        ) {
            return;
        }

        $tenantId = $this->teamTenantAccess->deriveTenantId(
            $entity,
            'Google Ads destination',
            includePersistedTeams: true,
        );

        if ($tenantId !== null) {
            $entity->set('tenantId', $tenantId);
        }
    }
}

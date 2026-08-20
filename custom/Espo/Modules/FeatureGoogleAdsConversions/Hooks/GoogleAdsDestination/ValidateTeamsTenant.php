<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureGoogleAdsConversions\Hooks\GoogleAdsDestination;

use Espo\Modules\Global\Hook\ValidateTeamsTenantBase;
use Espo\ORM\Entity;

class ValidateTeamsTenant extends ValidateTeamsTenantBase
{
    public static int $order = 10;

    protected function label(Entity $entity): string
    {
        return 'Google Ads destination';
    }
}

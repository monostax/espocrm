<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureTrackingEvent\Hooks\TrackingSource;

use Espo\Modules\Global\Hook\ValidateTeamsTenantBase;
use Espo\ORM\Entity;

/**
 * Refuses assigning this tracking source to another workspace's teams.
 *
 * See ValidateTeamsTenantBase for why `teams` is the sensitive field here.
 */
class ValidateTeamsTenant extends ValidateTeamsTenantBase
{
    public static int $order = 10;

    protected function label(Entity $entity): string
    {
        return 'tracking source';
    }
}

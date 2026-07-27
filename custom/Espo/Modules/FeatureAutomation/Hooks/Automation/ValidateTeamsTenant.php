<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureAutomation\Hooks\Automation;

use Espo\Modules\Global\Hook\ValidateTeamsTenantBase;
use Espo\ORM\Entity;

/**
 * `teams` drives the derived `tenantId` (AssignTenantFromTeam) and is cascaded
 * onto AutomationRun / AutomationRunItem rows, so assigning another workspace's
 * team both names a foreign tenant to read from and publishes this automation's
 * run payloads to it.
 */
class ValidateTeamsTenant extends ValidateTeamsTenantBase
{
    public static int $order = 10;

    protected function label(Entity $entity): string
    {
        return 'automation';
    }
}

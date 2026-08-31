<?php

declare(strict_types=1);

/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

namespace Espo\Modules\Global\Hooks\OpportunityStage;

use Espo\Modules\Global\Hook\ValidateTeamsTenantBase;
use Espo\ORM\Entity;

/**
 * Refuses assigning this stage to another workspace's teams.
 *
 * Stage teams normally mirror the parent funnel's and are therefore already
 * covered by Funnel/ValidateTeamsTenant. This closes the direct path: `teams`
 * is a writable linkMultiple on the stage itself, and since it now drives read
 * access, editing it directly would otherwise share the stage with a foreign
 * workspace.
 *
 * Runs at order 10, after InheritFunnelTeams (order 5), so an inherited team set
 * is validated too. Funnel/SyncStageTeams saves with `skipHooks`, so the
 * system-driven mirror does not re-enter this check.
 *
 * See ValidateTeamsTenantBase for why `teams` is the sensitive field here.
 */
class ValidateTeamsTenant extends ValidateTeamsTenantBase
{
    public static int $order = 10;

    protected function label(Entity $entity): string
    {
        return 'opportunity stage';
    }
}

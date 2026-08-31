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

namespace Espo\Modules\Global\Hooks\Funnel;

use Espo\Modules\Global\Hook\ValidateTeamsTenantBase;
use Espo\ORM\Entity;

/**
 * Refuses assigning this funnel to another workspace's teams.
 *
 * Required since Funnel became teams-only: `teams` now decides both the derived
 * tenantId and read access, so a foreign team both renames the owning tenant and
 * shares the funnel — plus its stages and opportunities — with that workspace.
 * The previous singular `team` link made this structurally impossible, because a
 * scalar team could only ever resolve to one tenant.
 *
 * Note that Funnel/SyncTenantFromTeam's ambiguity check does not cover this: it
 * returns early when tenantId is already set, so it never inspects the teams of
 * an existing funnel.
 *
 * See ValidateTeamsTenantBase for why `teams` is the sensitive field here.
 */
class ValidateTeamsTenant extends ValidateTeamsTenantBase
{
    public static int $order = 10;

    protected function label(Entity $entity): string
    {
        return 'funnel';
    }
}

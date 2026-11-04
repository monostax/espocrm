<?php
/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

namespace Espo\Modules\FeatureSelfService\Jobs;

use Espo\Core\Job\JobDataLess;
use Espo\Modules\FeatureSelfService\Tools\Workspace\WorkspaceAccess;

/**
 * Freezes self-service workspaces whose trial ended and keeps Chatwoot's copy
 * of each workspace's access in line, retrying pushes that failed.
 */
class ApplyWorkspaceAccess implements JobDataLess
{
    public function __construct(private WorkspaceAccess $access) {}

    public function run(): void
    {
        $this->access->freezeEndedTrials();
        $this->access->syncChatwoot();
    }
}

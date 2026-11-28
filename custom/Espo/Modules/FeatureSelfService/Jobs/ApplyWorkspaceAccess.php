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
use Espo\Modules\FeatureSelfService\Tools\Workspace\WorkspaceWallet;
use Espo\Modules\FeatureSelfService\Tools\Plans\PlanChange;
use Espo\Modules\FeatureSelfService\Tools\Plans\SeatAccess;

/**
 * Applies plan changes whose difference was paid, applies paid plans (active, past due, frozen after the grace days), freezes
 * self-service workspaces whose trial ended, grants started trials their
 * credits on the wallet, and keeps Chatwoot's copy
 * of each workspace's access in line, retrying pushes that failed.
 */
class ApplyWorkspaceAccess implements JobDataLess
{
    public function __construct(private WorkspaceAccess $access, private WorkspaceWallet $wallet, private SeatAccess $seats,
        private PlanChange $planChange) {}

    public function run(): void
    {
        $this->planChange->settleAll();
        $this->access->applyBilling();
        $this->access->freezeEndedTrials();
        $this->wallet->grantStartedTrials();
        $this->access->syncChatwoot();
        $this->seats->syncPending();
    }
}

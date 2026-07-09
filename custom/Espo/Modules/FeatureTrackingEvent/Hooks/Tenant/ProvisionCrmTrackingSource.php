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

declare(strict_types=1);

namespace Espo\Modules\FeatureTrackingEvent\Hooks\Tenant;

use Espo\Modules\FeatureTrackingEvent\Services\CrmSourceProvisioner;
use Espo\ORM\Entity;

/**
 * Auto-provisions the per-tenant kind=CRM TrackingSource when a Tenant is
 * created, flipping internal CRM event tracking from opt-in to opt-out
 * (deactivate/delete the source to opt out — CrmSourceProvisioner never
 * resurrects an existing decision).
 *
 * Order: after Global's CreateBaseTeam (beforeSave, 5) and
 * SyncAclTeamsFromBaseUserTeam (afterSave, 7), so `baseUserTeamId` is
 * settled and the source lands in the tenant's base team (team-level ACL).
 *
 * Never throws — the provisioner swallows and logs its own failures, so
 * tenant creation can never break over a tracking-source hiccup.
 */
class ProvisionCrmTrackingSource
{
    public static int $order = 31;

    public function __construct(
        private CrmSourceProvisioner $provisioner,
    ) {}

    /**
     * @param array<string, mixed> $options
     */
    public function afterSave(Entity $entity, array $options): void
    {
        if (!$entity->isNew()) {
            return;
        }

        $tenantId = $entity->getId();

        if (!$tenantId) {
            return;
        }

        $this->provisioner->provision($tenantId);
    }
}

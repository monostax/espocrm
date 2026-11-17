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

namespace Espo\Modules\Chatwoot\Hooks\Tenant;

use Espo\Core\Utils\Log;
use Espo\Modules\Chatwoot\Tools\Team\TenantTeamMirror;
use Espo\ORM\Entity;
use Throwable;

/**
 * Re-asserts the CRM Teams that mirror the Tenant's Chatwoot teams
 * (see TenantTeamMirror) on every Tenant save, for two reasons:
 *
 *   - their names start with the Tenant name, so a Tenant rename renames them;
 *   - `otherUserTeams` is a linkMultiple field that
 *     Espo\Hooks\Common\FieldProcessing ($order = -11) syncs to exactly the
 *     submitted id list, so a PATCH without them would drop them from the
 *     Tenant. Same self-healing as Global Hooks\Tenant\CreateAdminTeam.
 *
 * Runs at $order = 9, after the Global provisioning and rename hooks (5-8).
 *
 * Pass `skipTenantTeamMirror => true` to opt out (fixtures, imports).
 */
class MirrorChatwootTeams
{
    public static int $order = 9;

    public function __construct(
        private TenantTeamMirror $mirror,
        private Log $log,
    ) {}

    /**
     * @param array<string, mixed> $options
     */
    public function afterSave(Entity $entity, array $options): void
    {
        if ($entity->isNew() || !empty($options['skipTenantTeamMirror'])) {
            return;
        }

        try {
            $this->mirror->ensureForTenant($entity);
        } catch (Throwable $e) {
            // Never fail the Tenant save over a mirrored team.
            $this->log->error(sprintf(
                'Chatwoot: failed to mirror Chatwoot teams of Tenant %s: %s',
                (string) $entity->getId(),
                $e->getMessage(),
            ));
        }
    }
}

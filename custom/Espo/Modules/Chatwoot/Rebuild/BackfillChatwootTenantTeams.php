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

namespace Espo\Modules\Chatwoot\Rebuild;

use Espo\Core\Rebuild\RebuildAction;
use Espo\Core\Utils\Log;
use Espo\Modules\Chatwoot\Tools\Team\TenantTeamMirror;
use Espo\ORM\EntityManager;
use Throwable;

/**
 * Backfills the "{tenantName} / {teamName}" CRM Team for ChatwootTeams that
 * predate Hooks\ChatwootTeam\MirrorTenantTeam, and repairs any pair that
 * drifted (missing team, stale name, dropped from Tenant.otherUserTeams).
 *
 * Idempotent, safe on every rebuild. MUST run after BackfillChatwootTenant,
 * which fills the ChatwootAccount.tenantId this resolves the Tenant from.
 */
class BackfillChatwootTenantTeams implements RebuildAction
{
    public function __construct(
        private EntityManager $entityManager,
        private TenantTeamMirror $mirror,
        private Log $log,
    ) {}

    public function process(): void
    {
        $mirrored = 0;
        $skipped = 0;
        $failed = 0;

        try {
            $chatwootTeams = $this->entityManager
                ->getRDBRepository('ChatwootTeam')
                ->find();
        } catch (Throwable $e) {
            $this->log->error('BackfillChatwootTenantTeams: could not list ChatwootTeams: ' . $e->getMessage());

            return;
        }

        foreach ($chatwootTeams as $chatwootTeam) {
            try {
                $this->mirror->ensureFor($chatwootTeam) !== null ? $mirrored++ : $skipped++;
            } catch (Throwable $e) {
                $failed++;

                $this->log->error(sprintf(
                    'BackfillChatwootTenantTeams: failed for ChatwootTeam %s: %s',
                    (string) $chatwootTeam->getId(),
                    $e->getMessage(),
                ));
            }
        }

        $this->log->info(
            "BackfillChatwootTenantTeams: complete. Mirrored: {$mirrored}, Skipped: {$skipped}, Failed: {$failed}"
        );
    }
}

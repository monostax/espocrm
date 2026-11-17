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

namespace Espo\Modules\Chatwoot\Hooks\ChatwootTeam;

use Espo\Core\Utils\Log;
use Espo\Modules\Chatwoot\Tools\Team\TenantTeamMirror;
use Espo\ORM\Entity;
use Throwable;

/**
 * Every ChatwootTeam owns a CRM Team "{tenantName} / {teamName}" in its Tenant
 * (see TenantTeamMirror).
 *
 * Unlike the other ChatwootTeam hooks this one does NOT skip `silent` saves:
 * teams made in Chatwoot itself only reach the CRM through
 * Jobs\SyncTeamsFromChatwoot, which saves silently. That job re-saves every
 * team on every run, so the work is limited to a new team or a changed name /
 * account. Rebuild\BackfillChatwootTenantTeams and Hooks\Tenant\
 * MirrorChatwootTeams repair anything missed.
 *
 * A failure never blocks the ChatwootTeam save or removal.
 *
 * Pass `skipTenantTeamMirror => true` to opt out (fixtures, imports).
 */
class MirrorTenantTeam
{
    public static int $order = 30;

    public function __construct(
        private TenantTeamMirror $mirror,
        private Log $log,
    ) {}

    /**
     * @param array<string, mixed> $options
     */
    public function afterSave(Entity $entity, array $options): void
    {
        if (!empty($options['skipTenantTeamMirror'])) {
            return;
        }

        if (
            !$entity->isNew()
            && !$entity->isAttributeChanged('name')
            && !$entity->isAttributeChanged('accountId')
        ) {
            return;
        }

        try {
            $this->mirror->ensureFor($entity);
        } catch (Throwable $e) {
            $this->log->error(sprintf(
                'Chatwoot: failed to mirror ChatwootTeam %s as a CRM team: %s',
                (string) $entity->getId(),
                $e->getMessage(),
            ));
        }
    }

    /**
     * @param array<string, mixed> $options
     */
    public function afterRemove(Entity $entity, array $options): void
    {
        if (!empty($options['skipTenantTeamMirror'])) {
            return;
        }

        try {
            $this->mirror->removeFor($entity);
        } catch (Throwable $e) {
            $this->log->error(sprintf(
                'Chatwoot: failed to remove the CRM team of ChatwootTeam %s: %s',
                (string) $entity->getId(),
                $e->getMessage(),
            ));
        }
    }
}

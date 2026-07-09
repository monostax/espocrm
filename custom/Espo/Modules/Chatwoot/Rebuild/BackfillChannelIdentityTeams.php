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

namespace Espo\Modules\Chatwoot\Rebuild;

use Espo\Core\Rebuild\RebuildAction;
use Espo\Core\Utils\Log;
use Espo\ORM\EntityManager;

/**
 * Backfills entity_team links for ContactChannelIdentity rows from
 * their parent Contact's teams.
 *
 * Tenant roles grant ContactChannelIdentity `read: team`; rows created
 * before the teams cascade existed carry NO entity_team links, so
 * every non-admin user saw an empty identity list — which disabled
 * the channel-picker's "Nova Conversa" rows despite valid identities.
 *
 * The live cascade is Hooks\ContactChannelIdentity\CascadeTeamsFromContact
 * (create / re-assignment) plus Hooks\Contact\CascadeTeamsToChannelIdentities
 * (contact team changes); this pass covers pre-existing rows.
 *
 * Pure INSERT..SELECT — strictly additive and idempotent (NOT EXISTS
 * guard), so repeated rebuilds are no-ops. Runs AFTER
 * BackfillChannelIdentities so freshly materialized rows are covered
 * in the same rebuild.
 */
class BackfillChannelIdentityTeams implements RebuildAction
{
    public function __construct(
        private EntityManager $entityManager,
        private Log $log,
    ) {}

    public function process(): void
    {
        $pdo = $this->entityManager->getPDO();

        if (!$this->tableExists($pdo, 'contact_channel_identity')) {
            $this->log->info(
                'BackfillChannelIdentityTeams: contact_channel_identity table missing; skipping'
            );

            return;
        }

        $sql = "
            INSERT INTO entity_team (entity_id, team_id, entity_type, deleted)
            SELECT cci.id, et.team_id, 'ContactChannelIdentity', false
            FROM contact_channel_identity cci
            INNER JOIN entity_team et
                ON et.entity_id = cci.contact_id
                AND et.entity_type = 'Contact'
                AND et.deleted = false
            WHERE cci.deleted = false
              AND cci.contact_id IS NOT NULL
              AND NOT EXISTS (
                SELECT 1 FROM entity_team e2
                WHERE e2.entity_id = cci.id
                  AND e2.entity_type = 'ContactChannelIdentity'
                  AND e2.team_id = et.team_id
              )
        ";

        $inserted = $pdo->exec($sql);

        $this->log->warning(
            "BackfillChannelIdentityTeams: inserted " . (int) $inserted . " entity_team link(s)"
        );
    }

    private function tableExists(\PDO $pdo, string $table): bool
    {
        try {
            $pdo->query("SELECT 1 FROM {$table} LIMIT 1");

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}

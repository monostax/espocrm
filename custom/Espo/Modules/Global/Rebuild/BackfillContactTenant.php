<?php
/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 *
 * This software and associated documentation files (the "Software") are
 * the proprietary and confidential information of Monostax.
 *
 * Unauthorized copying, distribution, modification, public display, or use
 * of this Software, in whole or in part, via any medium, is strictly
 * prohibited without the express prior written permission of Monostax.
 *
 * This Software is licensed, not sold. Commercial use of this Software
 * requires a valid license from Monostax.
 *
 * For licensing information, please visit: https://www.monostax.ai
 ************************************************************************/

namespace Espo\Modules\Global\Rebuild;

use Espo\Core\ORM\Entity as CoreEntity;
use Espo\Core\Rebuild\RebuildAction;
use Espo\Core\Utils\Log;
use Espo\ORM\EntityManager;
use Throwable;

/**
 * Backfill Contact.tenantId for rows created before the Contact→Tenant
 * link existed.
 *
 * Strategy: for each Contact with null tenantId, look up the first team in
 * its teams linkMultiple and resolve that team's owning Tenant
 * (via Tenant.baseUserTeam or tenantOtherUserTeam pivot).
 *
 * Contacts that cannot be resolved are logged and skipped — they will need
 * manual triage. Downstream the `tenant` field is required=true, so newly
 * created Contacts always carry a tenantId going forward.
 *
 * Idempotent: only touches Contacts where tenantId IS NULL.
 */
class BackfillContactTenant implements RebuildAction
{
    private const BATCH_SIZE = 500;

    public function __construct(
        private EntityManager $entityManager,
        private Log $log,
    ) {}

    public function process(): void
    {
        $this->log->info('Global Module: Starting Contact.tenantId backfill...');

        $pdo = $this->entityManager->getPDO();

        // Count remaining work for progress logging.
        $countStmt = $pdo->query(
            "SELECT COUNT(*) FROM `contact` WHERE `tenant_id` IS NULL AND `deleted` = 0"
        );
        $remaining = (int) $countStmt->fetchColumn();

        if ($remaining === 0) {
            $this->log->info('Global Module: No Contacts need tenantId backfill.');

            return;
        }

        $this->log->info("Global Module: {$remaining} Contact(s) need tenantId backfill.");

        $updated = 0;
        $skipped = 0;

        // Stream rows via ->sth() to keep memory flat even on large datasets.
        $collection = $this->entityManager
            ->getRDBRepository('Contact')
            ->where(['tenantId' => null])
            ->sth()
            ->find();

        foreach ($collection as $contact) {
            // NB: must use getLinkMultipleIdList() — raw ->find() does not
            // run the LinkMultiple loader pipeline, so $contact->get('teamsIds')
            // returns null even when entity_team rows exist.
            $teamIds = $contact instanceof CoreEntity
                ? $contact->getLinkMultipleIdList('teams')
                : [];

            if ($teamIds === []) {
                $this->log->warning(
                    "Global Module: Contact '{$contact->getId()}' has no teams; cannot derive tenantId."
                );
                $skipped++;

                continue;
            }

            $tenantId = null;

            foreach ($teamIds as $teamId) {
                if (!is_string($teamId) || $teamId === '') {
                    continue;
                }

                $tenantId = $this->resolveTenantIdFromTeam($teamId);

                if ($tenantId) {
                    break;
                }
            }

            if (!$tenantId) {
                $this->log->warning(
                    "Global Module: Contact '{$contact->getId()}' — none of its teams resolve to a Tenant."
                );
                $skipped++;

                continue;
            }

            try {
                // Use raw SQL UPDATE to avoid firing hooks (SyncContactPacienteId,
                // ValidateUniqueCpf) on every backfilled row — this is pure
                // data migration, not a user-driven change.
                $stmt = $pdo->prepare(
                    "UPDATE `contact` SET `tenant_id` = :tenantId WHERE `id` = :id AND `tenant_id` IS NULL"
                );
                $stmt->execute([
                    'tenantId' => $tenantId,
                    'id' => $contact->getId(),
                ]);

                if ($stmt->rowCount() > 0) {
                    $updated++;
                }
            } catch (Throwable $e) {
                $this->log->error(
                    "Global Module: Failed to backfill tenantId on Contact '{$contact->getId()}': "
                    . $e->getMessage()
                );
                $skipped++;
            }

            if ($updated > 0 && $updated % self::BATCH_SIZE === 0) {
                $this->log->info("Global Module: Backfilled {$updated} Contact(s) so far...");
            }
        }

        $this->log->info(
            "Global Module: Contact.tenantId backfill complete. Updated: {$updated}, Skipped: {$skipped}."
        );
    }

    private function resolveTenantIdFromTeam(string $teamId): ?string
    {
        $tenantByBase = $this->entityManager
            ->getRDBRepository('Tenant')
            ->select(['id'])
            ->where(['baseUserTeamId' => $teamId])
            ->findOne();

        if ($tenantByBase) {
            return $tenantByBase->getId();
        }

        $tenantByOther = $this->entityManager
            ->getRDBRepository('Tenant')
            ->select(['id'])
            ->join('otherUserTeams', 'otherUserTeams')
            ->where(['otherUserTeamsMiddle.teamId' => $teamId])
            ->findOne();

        return $tenantByOther?->getId();
    }
}

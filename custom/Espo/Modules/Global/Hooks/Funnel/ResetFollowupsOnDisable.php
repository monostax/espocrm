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

namespace Espo\Modules\Global\Hooks\Funnel;

use Espo\Core\ApplicationState;
use Espo\Core\Hook\Hook\AfterSave;
use Espo\Core\Utils\Log;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;

/**
 * Bulk-resets active follow-up loops when a funnel's automatic
 * follow-up toggle (`followupsEnabled`) is switched OFF.
 *
 * Every Opportunity in this funnel currently in
 * `followupStatus = 'FollowupActive'` (or with a non-null timer) is
 * stopped (`followupStatus = 'FollowupInactive'`, `followupTimer = NULL`)
 * while preserving `followupCount`, `followupLifetimeCount`, and
 * `followupAiAgentId` provenance. `version_number` is bumped so the AI
 * tools' optimistic concurrency can never clobber the stop.
 *
 * Re-enabling the toggle does NOT resurrect the stopped timers — a row
 * stays 'FollowupInactive' until a human or the AI explicitly schedules
 * a new timer.
 *
 * @implements AfterSave<Entity>
 */
class ResetFollowupsOnDisable implements AfterSave
{
    public static int $order = 30;

    public function __construct(
        private EntityManager $entityManager,
        private ApplicationState $applicationState,
        private Log $log,
    ) {}

    public function afterSave(Entity $entity, SaveOptions $options): void
    {
        if ($entity->isNew()) {
            return;
        }

        if (!$entity->isAttributeChanged('followupsEnabled')) {
            return;
        }

        if ($entity->get('followupsEnabled')) {
            // Toggled ON (or unchanged-truthy) — nothing to reset.
            return;
        }

        $modifiedById = $this->applicationState->hasUser()
            ? $this->applicationState->getUser()->getId()
            : 'system';

        $pdo = $this->entityManager->getPDO();

        $stmt = $pdo->prepare(
            "UPDATE opportunity "
            . "SET followup_status = 'FollowupInactive', "
            . "    followup_timer = NULL, "
            . "    modified_at = NOW(), "
            . "    modified_by_id = :modifiedById, "
            . "    version_number = COALESCE(version_number, 0) + 1 "
            . "WHERE funnel_id = :funnelId "
            . "  AND (followup_status = 'FollowupActive' OR followup_timer IS NOT NULL) "
            . "  AND deleted = false"
        );

        $stmt->execute([
            ':modifiedById' => $modifiedById,
            ':funnelId' => $entity->getId(),
        ]);

        $count = $stmt->rowCount();

        $this->log->info(
            "Global Module: followupsEnabled disabled on funnel {$entity->getId()} — "
            . "stopped {$count} active follow-up opportunity(ies) (set to FollowupInactive)."
        );
    }
}

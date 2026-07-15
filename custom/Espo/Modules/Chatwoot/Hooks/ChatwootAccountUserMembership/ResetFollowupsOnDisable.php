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

namespace Espo\Modules\Chatwoot\Hooks\ChatwootAccountUserMembership;

use Espo\Core\ApplicationState;
use Espo\Core\Hook\Hook\AfterSave;
use Espo\Core\Utils\Log;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;

/**
 * Bulk-resets active follow-up loops when an AI agent's automatic
 * follow-up toggle (`followupsEnabled`) is switched OFF.
 *
 * Every Opportunity currently in `followupStatus = 'FollowupActive'`
 * whose follow-up loop is DRIVEN by this membership
 * (`followupAiAgentId = this membership`) is reset to `'ActionNeeded'`
 * with a NULL timer, so the scheduler never dispatches it again and the
 * row surfaces in the human "action needed" queue instead of silently
 * sitting active.
 *
 * Semantics mirror the backend's `resetActiveFollowupsForConversation`
 * (drizzle.crm.app/helpers.ts): status → 'ActionNeeded', count → 0,
 * timer → NULL, `version_number` bumped so the AI tools' optimistic
 * concurrency (send_followup_message bookkeeping PUTs) can never
 * clobber the reset. Raw UPDATE on purpose — this is a bulk state
 * normalisation, and per-row hook firing (e.g. audit stream spam)
 * is not desired; `followupAiAgentId` provenance is kept (Decision #10).
 *
 * Re-enabling the toggle does NOT resurrect the loops — a reset row
 * stays 'ActionNeeded' until a human or the AI re-arms it.
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
            . "SET followup_status = 'ActionNeeded', "
            . "    followup_count = 0, "
            . "    followup_timer = NULL, "
            . "    modified_at = NOW(), "
            . "    modified_by_id = :modifiedById, "
            . "    version_number = COALESCE(version_number, 0) + 1 "
            . "WHERE followup_ai_agent_id = :membershipId "
            . "  AND followup_status = 'FollowupActive' "
            . "  AND deleted = false"
        );

        $stmt->execute([
            ':modifiedById' => $modifiedById,
            ':membershipId' => $entity->getId(),
        ]);

        $count = $stmt->rowCount();

        $this->log->info(
            "Chatwoot Module: followupsEnabled disabled on membership {$entity->getId()} — "
            . "reset {$count} FollowupActive opportunity(ies) to ActionNeeded."
        );
    }
}

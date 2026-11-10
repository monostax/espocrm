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

namespace Espo\Modules\Global\Hooks\Opportunity;

use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Core\Utils\Metadata;
use Espo\Modules\Crm\Entities\Opportunity;
use Espo\Modules\Global\Classes\NextAction\PendingActivity;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;

/**
 * One next step at a time. While a person's next step is set (a pending
 * Task, Call or Meeting referenced by `nextActionId`), the AI follow-up is
 * paused: selecting a person's step pauses an active follow-up, and arming
 * a follow-up while such a step is pending is paused straight away. The UI
 * hands a deal back to the AI by clearing the step before arming.
 *
 * Pausing only clears `followupTimer`, so the cycle count is kept, as when
 * a user turns the follow-up off. Runs at $order = 17, before
 * {@see EnforceFollowupsEnabled} (18), {@see ResetFollowupCountOnStatusChange}
 * (19) and {@see EnforceFollowupTimerInvariant} (20), which derives
 * `followupStatus` from the timer.
 *
 * @implements BeforeSave<Opportunity>
 */
class PauseFollowupForPersonStep implements BeforeSave
{
    public static int $order = 17;

    public function __construct(
        private EntityManager $entityManager,
        private Metadata $metadata,
    ) {}

    /**
     * @param Opportunity $entity
     */
    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        if ($entity->get('followupTimer') === null) {
            return;
        }
        if (!$entity->isAttributeChanged('followupTimer') && !$entity->isAttributeChanged('nextActionId')) {
            return;
        }
        $type = $entity->get('nextActionType');
        $id = $entity->get('nextActionId');
        if (!$id || !in_array($type, PendingActivity::TYPES, true)) {
            return;
        }
        $activity = $this->entityManager->getEntityById($type, $id);
        if ($activity && PendingActivity::isPending($activity, $this->metadata)) {
            $entity->set('followupTimer', null);
        }
    }
}

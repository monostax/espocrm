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
use Espo\Modules\Crm\Entities\Opportunity;
use Espo\ORM\Entity;
use Espo\ORM\Repository\Option\SaveOptions;

/**
 * Clears `followupTimer` (and derives `followupStatus = 'FollowupInactive'`)
 * whenever an opportunity is closed (`status` in 'Won' or 'Lost'), including
 * attempts to schedule a follow-up timer on an already-closed opportunity.
 *
 * Runs AFTER {@see SyncFromOpportunityStage} (which sets `status` based on
 * probability, $order = 6) so that we observe the post-sync `status` value,
 * and BEFORE {@see ResetFollowupCountOnStatusChange} ($order = 19) and
 * {@see EnforceFollowupTimerInvariant} ($order = 20) so `followupCount` is
 * preserved and the derived badge is `FollowupInactive`.
 *
 * @implements BeforeSave<Opportunity>
 */
class AutoSetFollowupStatusOnClose implements BeforeSave
{
    public static int $order = 10;

    private const CLOSED_STATUSES = ['Won', 'Lost'];

    /**
     * @param Opportunity $entity
     */
    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        $status = $entity->get('status');

        if (!in_array($status, self::CLOSED_STATUSES, true)) {
            return;
        }

        if ($entity->get('followupTimer') !== null) {
            $entity->set('followupTimer', null);
        }

        if ($entity->get('followupStatus') !== 'FollowupInactive') {
            $entity->set('followupStatus', 'FollowupInactive');
        }
    }
}

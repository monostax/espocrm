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
 * Resets `followupCount` to 0 and clears `followupTimer` whenever
 * `followupStatus` transitions OUT of 'WaitingReply' to any other value.
 *
 * The counter only has meaning while the deal is in 'WaitingReply' — once
 * the prospect engages (status flips back to ActionNeeded), the user picks
 * a new workflow state, or the deal is closed (auto-flipped to Ended by
 * {@see AutoSetFollowupStatusOnClose}), the counter and timer are stale.
 *
 * Runs AFTER {@see AutoSetFollowupStatusOnClose} ($order = 10), so the
 * close-transition flip from WaitingReply → Ended is also captured here.
 *
 * @implements BeforeSave<Opportunity>
 */
class ResetFollowupCountOnStatusChange implements BeforeSave
{
    public static int $order = 15;

    /**
     * @param Opportunity $entity
     */
    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        if ($entity->isNew()) {
            return;
        }

        if (!$entity->isAttributeChanged('followupStatus')) {
            return;
        }

        $previous = $entity->getFetched('followupStatus');
        $current = $entity->get('followupStatus');

        // Only reset when leaving WaitingReply for something else.
        if ($previous !== 'WaitingReply' || $current === 'WaitingReply') {
            return;
        }

        $entity->set('followupCount', 0);
        $entity->set('followupTimer', null);
    }
}

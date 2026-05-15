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
 * Auto-flips `followupStatus` to 'Ended' whenever `status` transitions to
 * 'Won' or 'Lost'.
 *
 * Runs AFTER {@see SyncFromOpportunityStage} (which sets `status` based on
 * probability, $order = 6) so that we observe the post-sync `status` value.
 *
 * Users remain free to set `followupStatus` to any of the four values for
 * still-open opportunities; this hook only intervenes on the close transition.
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
        if (!$entity->isNew() && !$entity->isAttributeChanged('status')) {
            return;
        }

        $status = $entity->get('status');

        if (!in_array($status, self::CLOSED_STATUSES, true)) {
            return;
        }

        // Only overwrite if the user hasn't explicitly set it in this same save,
        // or if it's still at a non-final value. Users may legitimately want
        // 'Ended' to stick once set, so we always normalise on close.
        if ($entity->get('followupStatus') === 'Ended') {
            return;
        }

        $entity->set('followupStatus', 'Ended');
    }
}

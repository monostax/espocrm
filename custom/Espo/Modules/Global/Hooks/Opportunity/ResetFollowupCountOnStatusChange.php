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
 * Resets `followupCount` (current cycle) to 0 whenever `followupTimer`
 * changes to a non-null datetime (explicit scheduling / re-arming via
 * CRM, Chatwoot, or AI opportunity tools).
 *
 * Clearing `followupTimer` to null preserves `followupCount` (manual
 * stop, deal closure, or disabling follow-ups). `followupLifetimeCount`
 * is never reset here.
 *
 * Runs at $order = 19 — AFTER {@see AutoSetFollowupStatusOnClose}
 * ($order = 10) and {@see EnforceFollowupsEnabled} ($order = 18) so a
 * timer that was coerced to null on a closed or follow-up-disabled
 * opportunity does not reset `followupCount`.
 *
 * @implements BeforeSave<Opportunity>
 */
class ResetFollowupCountOnStatusChange implements BeforeSave
{
    public static int $order = 19;

    /**
     * @param Opportunity $entity
     */
    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        if (
            $entity->isAttributeChanged('followupTimer')
            && $entity->get('followupTimer') !== null
        ) {
            $entity->set('followupCount', 0);
        }
    }
}

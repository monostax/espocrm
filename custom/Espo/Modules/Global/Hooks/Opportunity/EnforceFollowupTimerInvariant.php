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
 * Derives the read-only `followupStatus` badge from `followupTimer`
 * nullability on `Opportunity`:
 *
 *   followupTimer !== null  ⇒  'FollowupActive'
 *   followupTimer === null  ⇒  'FollowupInactive'
 *
 * Runs at $order = 20 — AFTER {@see AutoSetFollowupStatusOnClose}
 * ($order = 10), {@see EnforceFollowupsEnabled} ($order = 18), and
 * {@see ResetFollowupCountOnStatusChange} ($order = 19) so `followupStatus`
 * always reflects the final `followupTimer` value.
 *
 * @implements BeforeSave<Opportunity>
 */
class EnforceFollowupTimerInvariant implements BeforeSave
{
    public static int $order = 20;

    /**
     * @param Opportunity $entity
     */
    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        $derivedStatus = $entity->get('followupTimer') !== null
            ? 'FollowupActive'
            : 'FollowupInactive';

        if ($entity->get('followupStatus') !== $derivedStatus) {
            $entity->set('followupStatus', $derivedStatus);
        }
    }
}

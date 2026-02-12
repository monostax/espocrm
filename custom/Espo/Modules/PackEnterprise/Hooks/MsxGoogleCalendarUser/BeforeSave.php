<?php

namespace Espo\Modules\PackEnterprise\Hooks\MsxGoogleCalendarUser;

use Espo\ORM\Entity;

/**
 * BeforeSave hook for MsxGoogleCalendarUser.
 * Sets `type = 'main'` on new records when the type is not already set.
 * Records created by the AfterSave hook (monitored calendars) already
 * have `type = 'monitored'` set explicitly, so this only affects
 * user-created records from the UI.
 */
class BeforeSave
{
    public static int $order = 9;

    public function beforeSave(Entity $entity, array $options = []): void
    {
        if ($entity->isNew() && empty($entity->get('type'))) {
            $entity->set('type', 'main');
        }
    }
}

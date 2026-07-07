<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureTrackingEvent\Classes\RecordHooks\TrackingEvent;

use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Record\DeleteParams;
use Espo\Core\Record\Hook\DeleteHook;
use Espo\Entities\User;
use Espo\Modules\FeatureTrackingEvent\Entities\TrackingEvent;
use Espo\ORM\Entity;

/**
 * Blocks deletion of TrackingEvent rows through the record API for
 * everyone except admins.
 *
 * The admin exception exists for data-hygiene / GDPR-erasure operations —
 * regular users must treat the ledger as immutable. Registered via
 * recordDefs/TrackingEvent.json `beforeDeleteHookClassNameList`.
 *
 * @implements DeleteHook<TrackingEvent>
 */
class BlockDelete implements DeleteHook
{
    public static int $order = 0;

    public function __construct(
        private User $user,
    ) {}

    public function process(Entity $entity, DeleteParams $params): void
    {
        if ($this->user->isAdmin()) {
            return;
        }

        throw new Forbidden(
            'TrackingEvent is an append-only ledger. Only administrators may delete rows.'
        );
    }
}

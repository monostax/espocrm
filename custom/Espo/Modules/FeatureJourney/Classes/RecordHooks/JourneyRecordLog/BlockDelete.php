<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureJourney\Classes\RecordHooks\JourneyRecordLog;

use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Record\DeleteParams;
use Espo\Core\Record\Hook\DeleteHook;
use Espo\Entities\User;
use Espo\ORM\Entity;

/** @implements DeleteHook<Entity> */
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
            'JourneyRecordLog is an append-only ledger. Only administrators may delete rows.'
        );
    }
}

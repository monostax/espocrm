<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureJourney\Classes\RecordHooks\JourneyRecordLog;

use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Record\Hook\SaveHook;
use Espo\ORM\Entity;

/** @implements SaveHook<Entity> */
class BlockWrite implements SaveHook
{
    public static int $order = 0;

    public function process(Entity $entity): void
    {
        throw new Forbidden(
            'JourneyRecordLog is an append-only ledger. Rows are created by the journey engine only.'
        );
    }
}

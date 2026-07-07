<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureTrackingEvent\Classes\RecordHooks\TrackingEvent;

use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Record\Hook\SaveHook;
use Espo\Modules\FeatureTrackingEvent\Entities\TrackingEvent;
use Espo\ORM\Entity;

/**
 * Makes TrackingEvent append-only at the API layer.
 *
 * Events are an immutable ledger: rows are created exclusively by
 * {@see \Espo\Modules\FeatureTrackingEvent\Services\TrackingEventIngester}
 * and mutated exclusively by background jobs (AnonymousStitcher) — both go
 * through EntityManager directly, which record hooks do NOT intercept.
 * Any create/update arriving through the record API (UI, REST, import) is
 * rejected here, for admins included.
 *
 * NOTE: this is the authoritative enforcement. The `recordDefs`
 * createDisabled/updateDisabled keys used by some sibling modules are NOT
 * consumed by this Espo version (dead metadata); clientDefs flags only
 * hide UI buttons.
 *
 * Registered for both beforeCreate and beforeUpdate via
 * recordDefs/TrackingEvent.json (SaveHook is accepted by both types).
 *
 * @implements SaveHook<TrackingEvent>
 */
class BlockWrite implements SaveHook
{
    public static int $order = 0;

    public function process(Entity $entity): void
    {
        throw new Forbidden(
            'TrackingEvent is an append-only ledger. ' .
            'Rows are created by the ingestion endpoint only and cannot be edited.'
        );
    }
}

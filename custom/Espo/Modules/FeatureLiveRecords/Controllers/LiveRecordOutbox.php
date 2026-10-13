<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureLiveRecords\Controllers;

use Espo\Core\Controllers\RecordBase;

/** The durable queue has no public CRUD surface, including for administrators. */
final class LiveRecordOutbox extends RecordBase
{
    protected function checkAccess(): bool
    {
        return false;
    }
}

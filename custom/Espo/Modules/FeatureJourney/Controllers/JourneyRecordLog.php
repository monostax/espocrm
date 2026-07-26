<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureJourney\Controllers;

use Espo\Core\Templates\Controllers\Base;

/**
 * Standard CRUD for JourneyRecordLog.
 * Required so ClassFinder maps GET/POST /JourneyRecordLog (no custom controller ⇒ 404).
 * Writes/deletes are rejected by record hooks (append-only ledger).
 */
class JourneyRecordLog extends Base
{
}

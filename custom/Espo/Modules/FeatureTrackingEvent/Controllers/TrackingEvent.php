<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureTrackingEvent\Controllers;

use Espo\Core\Templates\Controllers\Base;

/**
 * Standard CRUD controller for the TrackingEvent entity.
 *
 * Inherits list/read/delete actions from the Base template controller.
 * Create/update are blocked by aclDefs (create: "no") — events are
 * append-only and produced only by the TrackingEventReceiver ingestion
 * endpoint, not by manual user input.
 *
 * Espo resolves controllers by scanning Controllers/*.php files via
 * ClassFinder, so this stub is required for the entity to be reachable
 * from the UI (e.g. GET /TrackingEvent for the audit list view).
 */
class TrackingEvent extends Base
{
}

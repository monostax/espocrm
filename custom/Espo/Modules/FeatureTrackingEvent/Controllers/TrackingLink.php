<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureTrackingEvent\Controllers;

use Espo\Core\Templates\Controllers\Base;

/**
 * Standard CRUD controller for the TrackingLink entity.
 *
 * Inherits list/read/create/update/delete actions from the Base template
 * controller. Espo resolves controllers by scanning Controllers/*.php files
 * via ClassFinder, so this stub is required for the entity to be reachable
 * from the UI (e.g. GET /TrackingLink).
 *
 * The public redirect lives in TrackingLinkRedirect (noAuth); per-recipient
 * URL minting lives in TrackingLinkMint (authenticated).
 */
class TrackingLink extends Base
{
}

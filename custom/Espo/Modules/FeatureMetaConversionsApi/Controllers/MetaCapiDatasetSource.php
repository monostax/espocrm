<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureMetaConversionsApi\Controllers;

use Espo\Core\Templates\Controllers\Base;

/**
 * Standard CRUD controller for the MetaCapiDatasetSource entity.
 *
 * Inherits list/read/create/update/delete from the Base template controller.
 * Required so the entity is reachable from the UI (the sources panel on the
 * MetaCapiDataset detail view and the reverse panel on WhatsAppBusinessAccount).
 */
class MetaCapiDatasetSource extends Base
{
}

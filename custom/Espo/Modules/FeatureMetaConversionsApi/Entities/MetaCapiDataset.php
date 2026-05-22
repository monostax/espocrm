<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureMetaConversionsApi\Entities;

use Espo\Core\ORM\Entity;

/**
 * MetaCapiDataset entity — represents a single Meta dataset/pixel + access token.
 *
 * One tenant can have N datasets (e.g. one per brand or per funnel).
 */
class MetaCapiDataset extends Entity
{
    public const ENTITY_TYPE = 'MetaCapiDataset';

    public const STATUS_SUCCESS = 'Success';
    public const STATUS_FAILED = 'Failed';
}

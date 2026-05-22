<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureMetaConversionsApi\Entities;

use Espo\Core\ORM\Entity;

class MetaCapiEventLog extends Entity
{
    public const ENTITY_TYPE = 'MetaCapiEventLog';

    public const STATUS_PENDING = 'Pending';
    public const STATUS_SENT = 'Sent';
    public const STATUS_FAILED = 'Failed';
    public const STATUS_SKIPPED = 'Skipped';
}

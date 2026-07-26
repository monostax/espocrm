<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureAutomation\Entities;

use Espo\Core\ORM\Entity;

class AutomationRunItem extends Entity
{
    public const ENTITY_TYPE = 'AutomationRunItem';

    public const STATUS_PENDING = 'Pending';
    public const STATUS_PROCESSING = 'Processing';
    public const STATUS_WAITING = 'Waiting';
    public const STATUS_DONE = 'Done';
    public const STATUS_FAILED = 'Failed';
    public const STATUS_SKIPPED = 'Skipped';
    public const STATUS_RETRY = 'Retry';
}

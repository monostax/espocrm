<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureAutomation\Entities;

use Espo\Core\ORM\Entity;

class AutomationRun extends Entity
{
    public const ENTITY_TYPE = 'AutomationRun';

    public const STATUS_PENDING = 'Pending';
    public const STATUS_RUNNING = 'Running';
    public const STATUS_COMPLETED = 'Completed';
    public const STATUS_FAILED = 'Failed';
    public const STATUS_CANCELLED = 'Cancelled';
}

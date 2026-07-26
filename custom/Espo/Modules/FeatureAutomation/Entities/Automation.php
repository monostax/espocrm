<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureAutomation\Entities;

use Espo\Core\ORM\Entity;

class Automation extends Entity
{
    public const ENTITY_TYPE = 'Automation';

    public const STATUS_DRAFT = 'Draft';
    public const STATUS_ACTIVE = 'Active';
    public const STATUS_PAUSED = 'Paused';
    public const STATUS_ARCHIVED = 'Archived';

    public const KIND_BATCH = 'Batch';
    public const KIND_MACHINE = 'Machine';

    public const TRIGGER_MANUAL = 'manual';
    public const TRIGGER_SCHEDULE = 'schedule';
    public const TRIGGER_ENTITY_CHANGE = 'entityChange';
    public const TRIGGER_SIGNAL = 'signal';
}

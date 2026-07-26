<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureJourney\Entities;

use Espo\Core\ORM\Entity;

class JourneyRecord extends Entity
{

    public const ENTITY_TYPE = 'JourneyRecord';

    public const STATUS_ACTIVE = 'Active';
    public const STATUS_COMPLETED = 'Completed';
    public const STATUS_EXITED = 'Exited';
    public const STATUS_FAILED = 'Failed';
    public const STATUS_PAUSED = 'Paused';
    public const STATUS_PROCESSING = 'Processing';
}

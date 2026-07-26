<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureJourney\Entities;

use Espo\Core\ORM\Entity;

class Journey extends Entity
{

    public const ENTITY_TYPE = 'Journey';

    public const STATUS_DRAFT = 'Draft';
    public const STATUS_ACTIVE = 'Active';
    public const STATUS_PAUSED = 'Paused';
    public const STATUS_COMPLETED = 'Completed';
    public const STATUS_ARCHIVED = 'Archived';
}

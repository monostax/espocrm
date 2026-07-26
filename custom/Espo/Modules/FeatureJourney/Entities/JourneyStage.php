<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureJourney\Entities;

use Espo\Core\ORM\Entity;

class JourneyStage extends Entity
{

    public const ENTITY_TYPE = 'JourneyStage';

    public const TYPE_ENTRY = 'Entry';
    public const TYPE_NORMAL = 'Normal';
    public const TYPE_SUCCESS = 'Success';
    public const TYPE_EXIT = 'Exit';
}

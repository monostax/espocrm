<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureJourney\Entities;

use Espo\Core\ORM\Entity;

class JourneyStageAction extends Entity
{

    public const ENTITY_TYPE = 'JourneyStageAction';

    public const TRIGGER_ON_ENTER = 'OnEnter';
    public const TRIGGER_ON_EXIT = 'OnExit';
}

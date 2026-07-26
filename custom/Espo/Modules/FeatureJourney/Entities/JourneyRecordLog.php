<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureJourney\Entities;

use Espo\Core\ORM\Entity;

class JourneyRecordLog extends Entity
{

    public const ENTITY_TYPE = 'JourneyRecordLog';

    public const FIRED_BY_SIGNAL = 'signal';
    public const FIRED_BY_TIMER = 'timer';
    public const FIRED_BY_MANUAL = 'manual';
    public const FIRED_BY_FORMULA = 'formula';
    public const FIRED_BY_USER = 'user';
    public const FIRED_BY_SYSTEM = 'system';
    public const FIRED_BY_ENTITY_CHANGE = 'entityChange';
}

<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureAutomation\Entities;

use Espo\Core\ORM\Entity;

class AutomationActionReceipt extends Entity
{
    public const ENTITY_TYPE = 'AutomationActionReceipt';

    public const SCOPE_ACTION = 'action';
    public const SCOPE_TRIGGER = 'trigger';
}

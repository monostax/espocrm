<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureTaskRecurrence\Services;

use Espo\Core\Exceptions\Forbidden;

class TaskRecurrenceSeries extends \Espo\Core\Record\Service
{
    public function __construct()
    {
        throw new Forbidden('Use the Task recurrence API.');
    }
}

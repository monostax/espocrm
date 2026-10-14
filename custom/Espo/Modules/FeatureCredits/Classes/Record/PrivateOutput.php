<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Classes\Record;

use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Record\Output\Filter;
use Espo\ORM\Entity;

/** Financial storage is not a generic record API. Use the authorized public controllers. */
final class PrivateOutput implements Filter
{
    public function filter(Entity $entity): void
    {
        throw new Forbidden('Use the credit workspace to read accounting records.');
    }
}

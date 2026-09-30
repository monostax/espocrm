<?php

declare(strict_types=1);

namespace Espo\Modules\FeaturePlaybook\Services;

use Espo\Core\Exceptions\Forbidden;

/** Also blocks generic MassAction/export/relationship services from bypassing the scoped API. */
abstract class InternalRecord extends \Espo\Core\Record\Service
{
    public function __construct()
    {
        throw new Forbidden('Use the opportunity playbooks API.');
    }
}

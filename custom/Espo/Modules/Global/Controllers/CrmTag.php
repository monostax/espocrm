<?php

declare(strict_types=1);

namespace Espo\Modules\Global\Controllers;

use Espo\Core\Templates\Controllers\Base;
use Espo\Core\Exceptions\Forbidden;
use Espo\Modules\Global\Tools\CrmTags;

class CrmTag extends Base
{
    public function getActionDefaultWorkspace(): \stdClass
    {
        if (!$this->acl->checkScope('CrmTag', 'create')) {
            throw new Forbidden();
        }
        return $this->injectableFactory->create(CrmTags::class)->defaultWorkspace();
    }
}

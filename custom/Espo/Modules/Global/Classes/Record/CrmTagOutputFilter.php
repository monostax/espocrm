<?php

declare(strict_types=1);

namespace Espo\Modules\Global\Classes\Record;

use Espo\Core\Record\Output\Filter;
use Espo\Modules\Global\Tools\CrmTags;
use Espo\ORM\Entity;

class CrmTagOutputFilter implements Filter
{
    public function __construct(private CrmTags $tags) {}

    public function filter(Entity $entity): void
    {
        $this->tags->filterOutput($entity);
    }
}

<?php

declare(strict_types=1);

namespace Espo\ORM\Repository;

use Closure;
use Espo\ORM\Entity;

/** Persistence observer, independent of optional business hooks. */
interface MutationObserver
{
    /** @param Entity[] $entities */
    public function run(array $entities, string $operation, Closure $persist): mixed;
}

<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureSelfService\Tools\Plans;

use Espo\Core\Select\AccessControl\Applier;
use Espo\Entities\User;
use Espo\ORM\Query\SelectBuilder;

class SeatSelectAccess extends \Espo\Core\Select\AccessControl\Applier
{
    public function __construct(
        private string $entityType,
        private User $user,
        private Applier $base,
        private SeatBoundary $boundary,
    ) {}

    public function apply(SelectBuilder $queryBuilder): void
    {
        $this->base->apply($queryBuilder);
        $this->boundary->apply($this->user, $this->entityType, $queryBuilder);
    }
}

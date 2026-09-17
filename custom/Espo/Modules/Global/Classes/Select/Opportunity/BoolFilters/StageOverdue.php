<?php

declare(strict_types=1);

namespace Espo\Modules\Global\Classes\Select\Opportunity\BoolFilters;

use DateTimeZone;
use Espo\Core\Select\Bool\Filter;
use Espo\Core\Utils\DateTime\Clock;
use Espo\ORM\Query\SelectBuilder;
use Espo\ORM\Query\Part\Where\OrGroupBuilder;
use Espo\ORM\Query\Part\Condition as Cond;

class StageOverdue implements Filter
{
    public function __construct(private Clock $clock) {}

    public function apply(SelectBuilder $queryBuilder, OrGroupBuilder $orGroupBuilder): void
    {
        $orGroupBuilder->add(Cond::and(
            Cond::equal(Cond::column('status'), 'Open'),
            Cond::notEqual(Cond::column('currentStageVisitId'), null),
            Cond::less(Cond::column('stageDueAt'),
                $this->clock->now()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s')),
        ));
    }
}

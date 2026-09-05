<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureSimpleJourney\Classes\Select;

use Espo\Core\Select\AccessControl\Filter;
use Espo\Core\Select\SelectBuilderFactory;
use Espo\Entities\User;
use Espo\ORM\Query\SelectBuilder;

/** Parent links are visible only when their owning journey record is visible. */
class AccessibleRecord implements Filter
{
    public function __construct(private User $user, private SelectBuilderFactory $selectBuilderFactory) {}

    public function apply(SelectBuilder $queryBuilder): void
    {
        if ($this->user->isAdmin()) {
            return;
        }

        $records = $this->selectBuilderFactory->create()
            ->from('SimpleJourneyRecord')
            ->forUser($this->user)
            ->withStrictAccessControl()
            ->buildQueryBuilder()
            ->select(['id'])
            ->build();

        $queryBuilder->where(['recordId=s' => $records]);
    }
}

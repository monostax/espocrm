<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureInitiative\Classes\Select;

use Espo\Core\Select\AccessControl\Filter;
use Espo\Core\Select\SelectBuilderFactory;
use Espo\Entities\User;
use Espo\ORM\Query\SelectBuilder;

/** Relations are visible only when their owning initiative is visible. */
class AccessibleInitiative implements Filter
{
    public function __construct(private User $user, private SelectBuilderFactory $selectBuilderFactory) {}

    public function apply(SelectBuilder $queryBuilder): void
    {
        if ($this->user->isAdmin()) {
            return;
        }

        $initiatives = $this->selectBuilderFactory->create()
            ->from('Initiative')
            ->forUser($this->user)
            ->withStrictAccessControl()
            ->buildQueryBuilder()
            ->select(['id'])
            ->build();

        $queryBuilder->where(['initiativeId=s' => $initiatives]);
    }
}

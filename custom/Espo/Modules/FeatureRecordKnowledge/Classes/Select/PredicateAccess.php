<?php
declare(strict_types=1);
namespace Espo\Modules\FeatureRecordKnowledge\Classes\Select;

use Espo\Core\Select\Applier\AdditionalApplier;
use Espo\Core\Select\SearchParams;
use Espo\ORM\Query\SelectBuilder;
use Espo\Modules\FeatureRecordKnowledge\Services\Tenancy;

class PredicateAccess implements AdditionalApplier
{
    public function __construct(private Tenancy $tenancy) {}
    public function apply(SelectBuilder $queryBuilder, SearchParams $searchParams): void
    {
        $ids = $this->tenancy->ids();
        if ($ids !== null) $queryBuilder->where(['tenantId' => $ids]);
    }
}

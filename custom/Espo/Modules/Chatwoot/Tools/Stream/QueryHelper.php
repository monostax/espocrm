<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Tools\Stream;

use Espo\Core\AclManager;
use Espo\Core\Select\SearchParams;
use Espo\Core\Select\SelectBuilderFactory;
use Espo\Entities\User;
use Espo\ORM\EntityManager;
use Espo\ORM\Query\SelectBuilder;

/** Stock streams without search params bypass selectDefs, including pinned Notes. */
class QueryHelper extends \Espo\Tools\Stream\RecordService\QueryHelper
{
    public function __construct(
        EntityManager $entityManager,
        SelectBuilderFactory $selectBuilderFactory,
        AclManager $aclManager,
        private User $user,
        private OpportunityEventAccess $access,
    ) {
        parent::__construct($entityManager, $selectBuilderFactory, $aclManager);
    }

    public function buildBaseQueryBuilder(SearchParams $searchParams): SelectBuilder
    {
        return parent::buildBaseQueryBuilder($searchParams)->where($this->access->where($this->user));
    }
}

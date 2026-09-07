<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Classes\Select\Opportunity\AccessControlFilters;

use Espo\Core\Select\AccessControl\Filter;
use Espo\Entities\User;
use Espo\Modules\Global\Tools\Tenant\UserTenantResolver;
use Espo\ORM\Query\SelectBuilder;

class Tenant implements Filter
{
    public function __construct(private User $user, private UserTenantResolver $tenants) {}

    public function apply(SelectBuilder $queryBuilder): void
    {
        if (!$this->user->isAdmin()) {
            $queryBuilder->where(['tenantId' => $this->tenants->resolveTenantIds($this->user)]);
        }
    }
}

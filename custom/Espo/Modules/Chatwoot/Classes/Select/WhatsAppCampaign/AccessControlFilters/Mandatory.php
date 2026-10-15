<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Classes\Select\WhatsAppCampaign\AccessControlFilters;

use Espo\Core\Select\AccessControl\Filter;
use Espo\Entities\User;
use Espo\Modules\Global\Tools\Tenant\UserTenantResolver;
use Espo\ORM\Query\SelectBuilder;

class Mandatory implements Filter
{
    public function __construct(private User $user, private UserTenantResolver $tenants) {}

    public function apply(SelectBuilder $queryBuilder): void
    {
        if ($this->user->isAdmin()) return;
        $ids = $this->user->isRegular() && $this->user->isActive() ? $this->tenants->resolveTenantIds($this->user) : [];
        $queryBuilder->where(['OR' => [['managedByChatwoot' => false], ['tenantId' => $ids]]]);
    }
}

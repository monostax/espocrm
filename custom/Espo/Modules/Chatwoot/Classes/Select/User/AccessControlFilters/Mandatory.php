<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Classes\Select\User\AccessControlFilters;

use Espo\Core\Acl\Permission;
use Espo\Core\Acl\Table;
use Espo\Core\AclManager;
use Espo\Core\Select\AccessControl\Filter;
use Espo\Entities\User;
use Espo\Modules\Chatwoot\Classes\Select\User\ManagedAgents;
use Espo\ORM\Query\Part\Condition as Cond;
use Espo\ORM\Query\Part\Expression as Expr;
use Espo\ORM\Query\SelectBuilder;

/**
 * Core User mandatory filter, except managed AI agents stay listable for non-admins
 * so they can be picked as record owners. Team/own scoping still applies on top.
 */
class Mandatory implements Filter
{
    public function __construct(
        private User $user,
        private AclManager $aclManager,
    ) {}

    public function apply(SelectBuilder $queryBuilder): void
    {
        if (!$this->user->isEffectiveAdmin()) {
            $queryBuilder->where(['isActive' => true]);
            $queryBuilder->where(Cond::or(
                Cond::notEqual(Expr::column('type'), User::TYPE_API),
                ManagedAgents::idIn(),
            ));
        }

        if ($this->aclManager->getPermissionLevel($this->user, Permission::PORTAL) !== Table::LEVEL_YES) {
            $queryBuilder->where([
                'OR' => [
                    'type!=' => User::TYPE_PORTAL,
                    'id' => $this->user->getId(),
                ],
            ]);
        }

        if (!$this->user->isSuperAdmin()) {
            $queryBuilder->where(['type!=' => User::TYPE_SUPER_ADMIN]);
        }

        $queryBuilder->where(['type!=' => User::TYPE_SYSTEM]);
    }
}

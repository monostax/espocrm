<?php
declare(strict_types=1);
namespace Espo\Modules\FeatureAiSession\Classes;

use Espo\Core\Select\AccessControl\Filter;
use Espo\Entities\User;
use Espo\Modules\Global\Tools\Tenant\UserTenantResolver;
use Espo\ORM\Query\SelectBuilder;

class OwnerFilter implements Filter
{
    public function __construct(private User $user, private UserTenantResolver $tenants, private \Espo\Core\InjectableFactory $factory) {}

    public function apply(SelectBuilder $queryBuilder): void
    {
        // CRM instance administrators retain administrative access, as in stock Espo.
        if (!$this->user->isActive() || (!$this->user->isRegular() && !$this->user->isAdmin())) {
            $queryBuilder->where(['id' => null]);
            return;
        }
        if ($this->user->isAdmin()) return;
        $memberships = SelectBuilder::create()->from('ChatwootAccountUserMembership')->select('chatwootAccountId')
            ->join('ChatwootUser', 'sessionIdentity', ['sessionIdentity.id:' => 'chatwootUserId'])
            ->join('ChatwootAccount', 'sessionMembershipAccount', ['sessionMembershipAccount.id:' => 'chatwootAccountId'])
            ->where(['isAI' => false, 'sessionIdentity.deleted' => false, 'sessionMembershipAccount.deleted' => false,
                'sessionIdentity.assignedUserId' => $this->user->getId(),
                'sessionIdentity.platformId:' => 'sessionMembershipAccount.platformId'])->build();
        $select = $this->factory->createWith(\Espo\Core\Select\SelectBuilderFactory::class, ['user' => $this->user]);
        $accounts = $select->create()->from('ChatwootAccount')->withStrictAccessControl()->buildQueryBuilder()->select('id')->order([])
            ->where(['tenantId' => $this->tenants->resolveTenantIds($this->user), 'id=s' => $memberships])->build();
        $queryBuilder->join('ChatwootAccount', 'sessionAccount', ['sessionAccount.id:' => 'chatwootAccountId'])
            ->where(['assignedUserId' => $this->user->getId(), 'chatwootAccountId=s' => $accounts, 'sessionAccount.tenantId:' => 'tenantId']);
    }
}

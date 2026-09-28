<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureAiUsage\Services;

use Espo\Core\Exceptions\Forbidden;
use Espo\Entities\User;
use Espo\Modules\Global\Classes\Utils\TenantRoleAuth;
use Espo\Modules\Global\Tools\Tenant\TenantResolver;
use Espo\Modules\Global\Tools\Tenant\UserTenantResolver;
use Espo\ORM\EntityManager;

class Access
{
    public function __construct(
        private User $user,
        private EntityManager $entityManager,
        private TenantResolver $tenantResolver,
        private UserTenantResolver $userTenantResolver,
    ) {}

    /** null means instance administrator; an empty list means no access. */
    public function tenantIds(): ?array
    {
        if ($this->user->isPortal() || $this->user->isApi()) {
            return [];
        }
        if ($this->user->isAdmin()) {
            return null;
        }

        $adminRoleIds = TenantRoleAuth::tenantAdminRoleIds();
        if (array_intersect($adminRoleIds, $this->user->getLinkMultipleIdList('roles'))) {
            return $this->userTenantResolver->resolveTenantIds($this->user);
        }

        $teamIds = $this->user->getTeamIdList();
        if (!$teamIds) {
            return [];
        }
        $adminTeams = $this->entityManager->getRDBRepository('Team')
            ->select(['id'])->where(['id' => $teamIds])
            ->join('roles')->where(['roles.id' => $adminRoleIds])->distinct()->find();
        $ids = [];
        foreach ($adminTeams as $team) {
            $ids[] = $team->getId();
        }
        if (!$ids) {
            return [];
        }

        return array_values(array_intersect(
            $this->userTenantResolver->resolveTenantIds($this->user),
            $this->tenantResolver->resolveAllFromTeamIds($ids),
        ));
    }

    public function assertTenant(string $tenantId): void
    {
        $ids = $this->tenantIds();
        if ($tenantId === '' || ($ids !== null && !in_array($tenantId, $ids, true))) {
            throw new Forbidden('Tenant administrator access is required.');
        }
        if (!$this->entityManager->getEntityById('Tenant', $tenantId)) {
            throw new Forbidden('Tenant administrator access is required.');
        }
    }

    public function tenants(): array
    {
        $ids = $this->tenantIds();
        if ($ids === []) {
            return [];
        }
        $query = $this->entityManager->getRDBRepository('Tenant')->select(['id', 'name'])->order('name');
        if ($ids !== null) {
            $query->where(['id' => $ids]);
        }
        $result = [];
        foreach ($query->find() as $tenant) {
            $result[] = ['id' => $tenant->getId(), 'name' => $tenant->get('name')];
        }
        return $result;
    }
}

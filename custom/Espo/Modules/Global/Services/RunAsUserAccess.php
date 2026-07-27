<?php

declare(strict_types=1);

namespace Espo\Modules\Global\Services;

use Espo\Core\ApplicationState;
use Espo\Core\Exceptions\Forbidden;
use Espo\Entities\User;
use Espo\Modules\Global\Classes\Utils\TenantRoleAuth;
use Espo\Modules\Global\Tools\Tenant\UserTenantResolver;
use Espo\ORM\EntityManager;

/**
 * Who may configure Automation/Journey runAsUser.
 *
 * - Instance admin: any eligible active internal user
 * - tenant-admin: eligible users belonging to the entity tenant (or actor tenants)
 * - regular tenant user: self only
 */
class RunAsUserAccess
{
    public function __construct(
        private EntityManager $entityManager,
        private ApplicationState $applicationState,
        private UserTenantResolver $userTenantResolver,
    ) {}

    /**
     * @throws Forbidden
     */
    public function assertCanSet(?string $runAsUserId, ?string $entityTenantId): void
    {
        if ($runAsUserId === null || $runAsUserId === '') {
            return;
        }

        if (!$this->applicationState->isLogged()) {
            throw new Forbidden('Authentication required to set runAsUser.');
        }

        $actor = $this->applicationState->getUser();
        $target = $this->entityManager->getEntityById(User::ENTITY_TYPE, $runAsUserId);

        if (!$target instanceof User || !$this->isEligibleRunAsUser($target)) {
            throw new Forbidden('runAsUser must be an active non-system internal user.');
        }

        if (TenantRoleAuth::isInstanceAdmin($actor)) {
            return;
        }

        // Past this point the setter is NOT an instance admin. An Espo admin bypasses
        // ACL entirely (AclManager), so letting a tenant-admin borrow an admin identity
        // as runAsUser would escalate them to instance-wide reads through any
        // Journey/Automation filter. Only instance admins may delegate to an admin.
        if ($target->isAdmin() || $target->isSuperAdmin()) {
            throw new Forbidden(
                'runAsUser may not be an administrator. Only an instance administrator '
                . 'can delegate an automation to an admin identity.'
            );
        }

        if ($this->hasStrictTenantAdminRole($actor)) {
            if ($entityTenantId !== null && $entityTenantId !== '') {
                if (!$this->userBelongsToTenant($runAsUserId, $entityTenantId)) {
                    throw new Forbidden('runAsUser must belong to the same workspace (tenant).');
                }

                return;
            }

            if (!$this->userSharesActorTenants($actor, $runAsUserId)) {
                throw new Forbidden('runAsUser must belong to your workspace (tenant).');
            }

            return;
        }

        if ($actor->getId() !== $runAsUserId) {
            throw new Forbidden('You may only set runAsUser to yourself.');
        }
    }

    public function isEligibleRunAsUser(User $user): bool
    {
        if (!$user->isActive()) {
            return false;
        }

        if ($user->isSystem() || $user->isPortal()) {
            return false;
        }

        return $user->isRegular() || $user->isAdmin() || $user->isApi() || $user->isSuperAdmin();
    }

    /**
     * Team IDs that form every Tenant the given user belongs to (base + other User teams).
     *
     * @return list<string>
     */
    public function getTenantTeamIdsForUser(User $user): array
    {
        $tenantIds = $this->getUserTenantIds($user);
        if ($tenantIds === []) {
            return [];
        }

        $teamIds = [];
        foreach ($tenantIds as $tenantId) {
            foreach ($this->getTenantTeamIds($tenantId) as $teamId) {
                $teamIds[] = $teamId;
            }
        }

        return array_values(array_unique($teamIds));
    }

    /**
     * @return list<string>
     */
    public function getTenantTeamIds(string $tenantId): array
    {
        $tenant = $this->entityManager->getEntityById('Tenant', $tenantId);
        if (!$tenant) {
            return [];
        }

        $ids = [];
        $base = $tenant->get('baseUserTeamId');
        if ($base) {
            $ids[] = (string) $base;
        }

        try {
            $others = $this->entityManager
                ->getRDBRepository('Tenant')
                ->getRelation($tenant, 'otherUserTeams')
                ->find();

            foreach ($others as $team) {
                $ids[] = (string) $team->getId();
            }
        } catch (\Throwable) {
        }

        return array_values(array_unique($ids));
    }

    public function userBelongsToTenant(string $userId, string $tenantId): bool
    {
        if ($userId === '' || $tenantId === '') {
            return false;
        }

        $user = $this->entityManager->getEntityById(User::ENTITY_TYPE, $userId);
        if (!$user instanceof User) {
            return false;
        }

        // isAdmin covers super-admin. Admins bypass ACL, so they are never a
        // tenant member for delegation purposes — treating one as "in tenant"
        // is what allowed a tenant-admin to borrow instance-wide read access.
        if ($user->isSystem() || $user->isPortal() || $user->isAdmin() || $user->isSuperAdmin()) {
            return false;
        }

        $allowed = $this->getTenantTeamIds($tenantId);
        if ($allowed === []) {
            return false;
        }

        return array_intersect($allowed, $user->getTeamIdList()) !== [];
    }

    private function hasStrictTenantAdminRole(User $user): bool
    {
        $roleIds = TenantRoleAuth::collectUserRoleIds($user, $this->entityManager);
        $adminRoleIds = TenantRoleAuth::tenantAdminRoleIds();

        foreach ($roleIds as $roleId) {
            if (in_array($roleId, $adminRoleIds, true)) {
                return true;
            }
        }

        return false;
    }

    private function userSharesActorTenants(User $actor, string $runAsUserId): bool
    {
        $teamIds = $this->getTenantTeamIdsForUser($actor);
        if ($teamIds === []) {
            return false;
        }

        $target = $this->entityManager->getEntityById(User::ENTITY_TYPE, $runAsUserId);
        if (!$target instanceof User) {
            return false;
        }

        return array_intersect($teamIds, $target->getTeamIdList()) !== [];
    }

    /**
     * Every tenant (workspace) the user can act for. A user may belong to more
     * than one by design.
     *
     * Delegates to UserTenantResolver so this and the other membership call
     * sites cannot drift. It previously had its own copy that let the explicit
     * `tenantUser` links SHADOW team-derived membership (teams were consulted
     * only when the explicit set was empty), and resolved otherUserTeams with an
     * N+1 loop.
     *
     * @return list<string>
     */
    public function getUserTenantIds(User $user): array
    {
        return $this->userTenantResolver->resolveTenantIds($user);
    }
}

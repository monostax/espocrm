<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureMetaConversionsApi\Classes\AppParams;

use Espo\Entities\User;
use Espo\ORM\EntityManager;
use Espo\Tools\App\AppParam;
use Throwable;

/**
 * AppParam: whether the current user's tenant has at least one active
 * MetaCapiDataset configured.
 *
 * Used by the client to gate visibility of CAPI-related fields/panels on
 * Funnel and OpportunityStage detail views — so tenants who haven't (yet)
 * configured the integration don't see dead configuration surfaces.
 *
 * Returns true for admins regardless of dataset state: admins need the
 * configuration UI to bootstrap the first dataset. For non-admins, returns
 * true only when an active dataset exists in a tenant they belong to (via
 * the Tenant.baseUserTeam → Team membership chain that mirrors the rest
 * of the multi-tenant boundary in this codebase).
 *
 * Exposed via /api/v1/App/user as appParams.metaCapiActive.
 */
class MetaCapiActive implements AppParam
{
    public function __construct(
        private User $user,
        private EntityManager $entityManager,
    ) {}

    public function get(): bool
    {
        // Admins always see CAPI surfaces so they can set up the first
        // dataset for any tenant. Without this, a fresh install would have
        // the toggle hidden forever and nobody could turn it on.
        if ($this->user->isAdmin()) {
            return true;
        }

        $tenantIds = $this->resolveUserTenantIds();

        if (empty($tenantIds)) {
            return false;
        }

        $count = $this->entityManager
            ->getRDBRepository('MetaCapiDataset')
            ->where([
                'isActive' => true,
                'tenantId' => $tenantIds,
                'deleted'  => false,
            ])
            ->count();

        return $count > 0;
    }

    /**
     * Resolve the tenant id(s) accessible to the current user.
     *
     * Path: User's teams → Tenant.baseUserTeam in those team ids.
     * Mirrors the lookup logic in
     * Espo\Modules\FeatureMetaLeadAds\Services\TenantResolver.
     *
     * @return list<string>
     */
    private function resolveUserTenantIds(): array
    {
        try {
            $teamIds = $this->user->getLinkMultipleIdList('teams');
        } catch (Throwable) {
            return [];
        }

        if (empty($teamIds)) {
            return [];
        }

        $tenants = $this->entityManager
            ->getRDBRepository('Tenant')
            ->select(['id'])
            ->where(['baseUserTeamId' => $teamIds, 'deleted' => false])
            ->find();

        $ids = [];

        foreach ($tenants as $t) {
            $tid = $t->getId();
            if (is_string($tid) && $tid !== '') {
                $ids[] = $tid;
            }
        }

        return array_values(array_unique($ids));
    }
}

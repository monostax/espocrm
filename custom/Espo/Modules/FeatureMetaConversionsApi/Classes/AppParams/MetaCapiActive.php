<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureMetaConversionsApi\Classes\AppParams;

use Espo\Entities\User;
use Espo\Modules\Global\Tools\Tenant\UserTenantResolver;
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
 * true only when an active dataset exists in a tenant they belong to, as
 * resolved by UserTenantResolver (direct tenant link, base user team and
 * other user teams) so this gate agrees with the read paths it guards.
 *
 * Exposed via /api/v1/App/user as appParams.metaCapiActive.
 */
class MetaCapiActive implements AppParam
{
    public function __construct(
        private User $user,
        private EntityManager $entityManager,
        private UserTenantResolver $userTenantResolver,
    ) {}

    public function get(): bool
    {
        // Admins always see CAPI surfaces so they can set up the first
        // dataset for any tenant. Without this, a fresh install would have
        // the toggle hidden forever and nobody could turn it on.
        if ($this->user->isAdmin()) {
            return true;
        }

        // UserTenantResolver deliberately lets query failures propagate, so that an
        // authorisation decision is never silently narrowed. This is only a UI gate
        // on /App/user, though, and letting it throw would fail app boot for every
        // user instead of hiding one panel — so degrade to "hidden" as before.
        try {
            $tenantIds = $this->userTenantResolver->resolveTenantIds($this->user);
        } catch (Throwable) {
            return false;
        }

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
}

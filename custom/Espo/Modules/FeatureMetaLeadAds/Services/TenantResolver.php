<?php
/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

declare(strict_types=1);

namespace Espo\Modules\FeatureMetaLeadAds\Services;

use Espo\Core\Utils\Log;
use Espo\Modules\Global\Tools\Tenant\TenantResolver as GlobalTenantResolver;

/**
 * Reusable Team → Tenant resolver for the Meta Lead Ads ingestion pipeline.
 *
 * Mirrors the lookup logic in the BeforeSave hook
 * `FeatureMetaLeadAds\Hooks\MetaFacebookPage\AssignTenantFromTeam` so that
 * services creating rows with `skipHooks => true` (PageSyncService,
 * FormSyncService, MetaLeadAdsWebhook::upsertEvent, LeadgenIngester)
 * can populate tenantId BEFORE save without relying on the hook (which
 * is bypassed by skipHooks AND by the silent guard at line 38).
 *
 * Lookup rule: `Tenant.baseUserTeam` IN ({teamIds}).
 *   - 0 matches → null (unresolved; caller decides what to do).
 *   - 1 match   → that tenantId.
 *   - >1 match  → null + warning (ambiguous — the page would belong to
 *                 multiple tenants; refuse to guess).
 *
 * The team -> tenant edge is delegated to the canonical Global TenantResolver,
 * which matches a tenant's base user team AND its other user teams. This used to
 * consider only `baseUserTeam`, justified as matching the hook exactly — but the
 * hook (MetaFacebookPage\AssignTenantFromTeam) now resolves through
 * TeamTenantAccess and counts both, so base-team-only would silently disagree
 * with the derivation it is supposed to mirror.
 */
class TenantResolver
{
    public function __construct(
        private GlobalTenantResolver $globalTenantResolver,
        private Log $log,
    ) {}

    /**
     * Resolve the unique Tenant id that owns one of the given team ids
     * via Tenant.baseUserTeam.
     *
     * @param list<string> $teamIds
     */
    public function resolveTenantIdFromTeamIds(array $teamIds): ?string
    {
        $teamIds = array_values(array_unique(array_filter($teamIds, 'is_string')));

        if (empty($teamIds)) {
            return null;
        }

        $tenantIds = $this->globalTenantResolver->resolveAllFromTeamIds($teamIds);

        if (count($tenantIds) === 0) {
            return null;
        }

        if (count($tenantIds) > 1) {
            $this->log->warning(
                'TenantResolver: team ids [' . implode(',', $teamIds) .
                '] resolve to multiple tenants; refusing to guess.'
            );

            return null;
        }

        return $tenantIds[0];
    }
}

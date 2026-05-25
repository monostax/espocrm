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
use Espo\ORM\EntityManager;

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
 * This service is intentionally narrow — it does NOT also look at
 * `Tenant.otherUserTeams` because the canonical "owns" relationship is
 * `baseUserTeam`. That matches the hook's behavior exactly.
 */
class TenantResolver
{
    public function __construct(
        private EntityManager $entityManager,
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

        $tenants = $this->entityManager
            ->getRDBRepository('Tenant')
            ->where(['baseUserTeamId' => $teamIds])
            ->find();

        $tenantIds = [];

        foreach ($tenants as $tenant) {
            $tenantIds[$tenant->getId()] = true;
        }

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

        return array_key_first($tenantIds);
    }
}

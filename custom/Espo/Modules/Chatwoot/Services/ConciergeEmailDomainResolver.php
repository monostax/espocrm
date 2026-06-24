<?php
/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 *
 * This software and associated documentation files (the "Software") are
 * the proprietary and confidential information of Monostax.
 *
 * Unauthorized copying, distribution, modification, public display, or use
 * of this Software, in whole or in part, via any medium, is strictly
 * prohibited without the express prior written permission of Monostax.
 *
 * This Software is licensed, not sold. Commercial use of this Software
 * requires a valid license from Monostax.
 *
 * For licensing information, please visit: https://www.monostax.ai
 ************************************************************************/

namespace Espo\Modules\Chatwoot\Services;

use Espo\Core\Utils\Config;
use Espo\Core\Utils\Log;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Resolves the email domain for Chatwoot concierge users.
 *
 * The concierge email follows the pattern:
 *
 *   concierge.<chatwootAccountId>@guest.<tenant-slug>.<gitops-domain>
 *
 * where:
 *   - <tenant-slug>   is the Tenant entity's unique `slug` field, resolved
 *                     from the ChatwootAccount's `tenant` link.
 *   - <gitops-domain> is the deployment's frontend tenant domain, injected
 *                     via the `CRM_FRONTEND_TENANT_DOMAIN` env var and
 *                     stored in config as `frontendTenantDomain`.
 *
 * When the ChatwootAccount has no linked Tenant (e.g. the system-seeded
 * "Default" account created by SeedChatwootAccount), the resolver falls
 * back to the bare gitops domain so the email is still unique (the
 * chatwootAccountId differentiates) and deliverable.
 *
 * Used by SyncWithChatwoot hook, SeedChatwootAccount, and
 * RotateAutomationToConcierge to keep the email convention consistent
 * across all concierge-creation paths.
 */
class ConciergeEmailDomainResolver
{
    public function __construct(
        private EntityManager $entityManager,
        private Config $config,
        private Log $log
    ) {}

    /**
     * Resolve the email domain for a concierge user tied to the given
     * ChatwootAccount.
     *
     * @param Entity|null $account The ChatwootAccount entity, or null when
     *                             the account entity does not exist yet
     *                             (e.g. seed creation path).
     * @return string The domain part after the '@' sign.
     */
    public function resolveDomain(?Entity $account): string
    {
        $gitopsDomain = $this->config->get('frontendTenantDomain');

        if (!$gitopsDomain) {
            $this->log->warning(
                'ConciergeEmailDomainResolver: frontendTenantDomain config is not set; '
                . 'falling back to monostax-ext.com'
            );
            return 'monostax-ext.com';
        }

        $tenantSlug = $this->resolveTenantSlug($account);

        if ($tenantSlug) {
            return 'guest.' . $tenantSlug . '.' . $gitopsDomain;
        }

        return $gitopsDomain;
    }

    /**
     * Build the full concierge email address for a given Chatwoot account ID.
     *
     * @param Entity|null $account           The ChatwootAccount entity, or null.
     * @param int         $chatwootAccountId The Chatwoot platform account ID.
     * @return string
     */
    public function resolveEmail(?Entity $account, int $chatwootAccountId): string
    {
        return 'concierge.' . $chatwootAccountId . '@' . $this->resolveDomain($account);
    }

    /**
     * Extract the tenant slug from the ChatwootAccount's linked Tenant.
     *
     * @param Entity|null $account
     * @return string|null The tenant slug, or null if no tenant is linked
     *                     or the tenant has no slug set.
     */
    private function resolveTenantSlug(?Entity $account): ?string
    {
        if (!$account) {
            return null;
        }

        $tenantId = $account->get('tenantId');

        if (!$tenantId) {
            return null;
        }

        $tenant = $this->entityManager->getEntityById('Tenant', $tenantId);

        if (!$tenant) {
            $this->log->warning(
                'ConciergeEmailDomainResolver: Tenant not found for ID ' . $tenantId
                . ' on ChatwootAccount ' . ($account->getId() ?? '(new)')
            );
            return null;
        }

        $slug = $tenant->get('slug');

        if (!$slug) {
            $this->log->warning(
                'ConciergeEmailDomainResolver: Tenant ' . $tenantId
                . ' has no slug set; falling back to bare gitops domain.'
            );
            return null;
        }

        return $slug;
    }
}

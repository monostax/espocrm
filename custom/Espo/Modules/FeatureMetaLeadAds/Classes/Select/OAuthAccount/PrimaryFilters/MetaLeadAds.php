<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureMetaLeadAds\Classes\Select\OAuthAccount\PrimaryFilters;

use Espo\Core\Select\Primary\Filter;
use Espo\ORM\Query\SelectBuilder;

/**
 * Primary filter restricting OAuthAccount results to accounts whose linked
 * OAuthProvider has provider == 'meta-leadads'.
 *
 * Used by the MetaFacebookPage UI to keep the OAuth account picker (both the
 * `oAuthAccount` link field and the "Sync Pages" list-action modal) scoped to
 * Meta Lead Ads accounts only — the server-side PageSyncService already
 * rejects anything else, this filter just stops users from picking an invalid
 * account in the first place.
 *
 * Registered under `selectDefs/OAuthAccount.json#primaryFilterClassNameMap`.
 *
 * The PROVIDER_DISCRIMINATOR value mirrors the constant defined in
 * SeedOAuthProviderMetaLeadAds and used throughout the module.
 */
class MetaLeadAds implements Filter
{
    private const PROVIDER_DISCRIMINATOR = 'meta-leadads';

    public function apply(SelectBuilder $queryBuilder): void
    {
        $queryBuilder
            ->leftJoin('provider', 'providerMetaLeadAdsFilter')
            ->where([
                'providerMetaLeadAdsFilter.provider' => self::PROVIDER_DISCRIMINATOR,
                'providerMetaLeadAdsFilter.deleted'  => false,
            ]);
    }
}

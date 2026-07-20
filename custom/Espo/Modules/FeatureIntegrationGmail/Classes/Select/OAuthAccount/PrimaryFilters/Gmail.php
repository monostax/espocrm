<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureIntegrationGmail\Classes\Select\OAuthAccount\PrimaryFilters;

use Espo\Core\Select\Primary\Filter;
use Espo\Modules\FeatureIntegrationGmail\Rebuild\SeedOAuthProviderGmail;
use Espo\ORM\Query\SelectBuilder;

/**
 * Primary filter restricting OAuthAccount results to Gmail provider accounts.
 *
 * Used by EmailAccount / InboundEmail oAuthAccount pickers so users cannot
 * attach a Meet/Calendar/Meta token by mistake.
 */
class Gmail implements Filter
{
    public function apply(SelectBuilder $queryBuilder): void
    {
        $queryBuilder
            ->leftJoin('provider', 'providerGmailFilter')
            ->where([
                'providerGmailFilter.provider' => SeedOAuthProviderGmail::PROVIDER_DISCRIMINATOR,
                'providerGmailFilter.deleted' => false,
            ]);
    }
}

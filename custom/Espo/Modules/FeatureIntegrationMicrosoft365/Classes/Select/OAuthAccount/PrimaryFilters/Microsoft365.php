<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureIntegrationMicrosoft365\Classes\Select\OAuthAccount\PrimaryFilters;

use Espo\Core\Select\Primary\Filter;
use Espo\Modules\FeatureIntegrationMicrosoft365\Rebuild\SeedOAuthProviderMicrosoft365;
use Espo\ORM\Query\SelectBuilder;

/**
 * Primary filter restricting OAuthAccount results to Microsoft 365 provider accounts.
 *
 * Used by EmailAccount / InboundEmail oAuthAccount pickers so users cannot
 * attach a Gmail/Meet/Calendar/Meta token by mistake.
 */
class Microsoft365 implements Filter
{
    public function apply(SelectBuilder $queryBuilder): void
    {
        $queryBuilder
            ->leftJoin('provider', 'providerMicrosoft365Filter')
            ->where([
                'providerMicrosoft365Filter.provider' => SeedOAuthProviderMicrosoft365::PROVIDER_DISCRIMINATOR,
                'providerMicrosoft365Filter.deleted' => false,
            ]);
    }
}

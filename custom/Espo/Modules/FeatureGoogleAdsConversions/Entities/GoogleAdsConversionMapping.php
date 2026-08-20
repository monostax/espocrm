<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureGoogleAdsConversions\Entities;

use Espo\Core\ORM\Entity;

class GoogleAdsConversionMapping extends Entity
{
    public const ENTITY_TYPE = 'GoogleAdsConversionMapping';

    public const VALUE_SOURCE_NONE = 'None';
    public const VALUE_SOURCE_OPPORTUNITY_AMOUNT = 'OpportunityAmount';
    public const VALUE_SOURCE_FIXED = 'Fixed';

    public const CURRENCY_SOURCE_OPPORTUNITY = 'OpportunityCurrency';
    public const CURRENCY_SOURCE_FIXED = 'Fixed';
}

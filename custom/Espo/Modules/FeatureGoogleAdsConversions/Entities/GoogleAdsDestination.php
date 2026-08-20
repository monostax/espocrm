<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureGoogleAdsConversions\Entities;

use Espo\Core\ORM\Entity;

class GoogleAdsDestination extends Entity
{
    public const ENTITY_TYPE = 'GoogleAdsDestination';

    public const STATUS_SUCCESS = 'Success';
    public const STATUS_FAILED = 'Failed';
}

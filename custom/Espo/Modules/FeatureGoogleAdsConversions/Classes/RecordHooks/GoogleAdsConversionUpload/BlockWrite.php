<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureGoogleAdsConversions\Classes\RecordHooks\GoogleAdsConversionUpload;

use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Record\Hook\SaveHook;
use Espo\Modules\FeatureGoogleAdsConversions\Entities\GoogleAdsConversionUpload;
use Espo\ORM\Entity;

/** @implements SaveHook<GoogleAdsConversionUpload> */
class BlockWrite implements SaveHook
{
    public static int $order = 0;

    public function process(Entity $entity): void
    {
        throw new Forbidden(
            'GoogleAdsConversionUpload rows are created and updated by the dispatch pipeline only.'
        );
    }
}

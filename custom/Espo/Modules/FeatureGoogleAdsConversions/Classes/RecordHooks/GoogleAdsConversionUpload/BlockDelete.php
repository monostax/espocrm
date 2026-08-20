<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureGoogleAdsConversions\Classes\RecordHooks\GoogleAdsConversionUpload;

use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Record\DeleteParams;
use Espo\Core\Record\Hook\DeleteHook;
use Espo\Entities\User;
use Espo\Modules\FeatureGoogleAdsConversions\Entities\GoogleAdsConversionUpload;
use Espo\ORM\Entity;

/** @implements DeleteHook<GoogleAdsConversionUpload> */
class BlockDelete implements DeleteHook
{
    public static int $order = 0;

    public function __construct(private User $user) {}

    public function process(Entity $entity, DeleteParams $params): void
    {
        if ($this->user->isAdmin()) {
            return;
        }

        throw new Forbidden('Only administrators may delete Google Ads conversion upload rows.');
    }
}

<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Hooks\Account;

class PurchasedErasure extends \Espo\Modules\FeatureCredits\Enrichment\PurchasedErasure
{
    public static int $order = 1;
}

<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Jobs;

use Espo\Core\Job\JobDataLess;
use Espo\Core\Utils\Config;
use Espo\Modules\FeatureCredits\Billing\Asaas\Events;

final class ReconcileBillingEvents implements JobDataLess
{
    public function __construct(private Events $events, private Config $config) {}
    public function run(): void
    {
        if ($this->config->get('featureCreditsBillingReconciliationEnabled') === true) $this->events->processBatch();
    }
}

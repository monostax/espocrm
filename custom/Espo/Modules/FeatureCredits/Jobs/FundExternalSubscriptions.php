<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Jobs;

use Espo\Core\Job\JobDataLess;
use Espo\Modules\FeatureCredits\Billing\ExternalSubscriptions;
use RuntimeException;

/** Monthly credits for hand-made Asaas subscriptions linked in config (see ExternalSubscriptions). */
final class FundExternalSubscriptions implements JobDataLess
{
    public function __construct(private ExternalSubscriptions $subscriptions) {}

    public function run(): void
    {
        $result = $this->subscriptions->fundAll();
        if ($result['failed'] > 0) {
            throw new RuntimeException('External subscription funding failed for ' . $result['failed'] . ' link(s). See CRM logs.');
        }
    }
}

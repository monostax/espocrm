<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Jobs;

use Espo\Core\Job\JobDataLess;
use Espo\Modules\FeatureCredits\Accounting\ExpirationSweep;
use RuntimeException;

final class ExpireCredits implements JobDataLess
{
    public function __construct(private ExpirationSweep $sweep) {}

    public function run(): void
    {
        $result = $this->sweep->run();
        if ($result['failed'] > 0) {
            throw new RuntimeException('Credit expiration failed for ' . $result['failed'] . ' tenant(s). See CRM logs.');
        }
    }
}

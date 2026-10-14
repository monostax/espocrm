<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Jobs;

use Espo\Core\Job\Job;
use Espo\Core\Job\Job\Data;
use Espo\Modules\FeatureCredits\Verification\Execution;

final class RunPhoneVerification implements Job
{
    public function __construct(private Execution $execution) {}

    public function run(Data $data): void
    {
        $tenantId = $data->get('tenantId');
        $operationId = $data->get('operationId');
        if (!is_string($tenantId) || !is_string($operationId)) {
            throw new \InvalidArgumentException('Phone verification job identity required.');
        }
        $this->execution->run($tenantId, $operationId);
    }
}

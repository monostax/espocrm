<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Jobs;

use Espo\Core\Job\JobDataLess;
use Espo\Modules\Chatwoot\Services\OpportunityOverdueActivities;

class PostOverdueOpportunityActivities implements JobDataLess
{
    public function __construct(private OpportunityOverdueActivities $service) {}

    public function run(): void
    {
        $this->service->process();
    }
}

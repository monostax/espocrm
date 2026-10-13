<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Jobs;

use Espo\Core\Job\Job;
use Espo\Core\Job\Job\Data;
use Espo\Modules\FeatureCredits\Enrichment\ExecutionRouter;

final class RunEnrichment implements Job
{
    public function __construct(private ExecutionRouter $execution) {}
    public function run(Data $data): void
    {
        $tenant = $data->get('tenantId'); $quote = $data->get('quoteId');
        if (!is_string($tenant) || !is_string($quote)) throw new \InvalidArgumentException('Enrichment job identity required.');
        $this->execution->run($tenant,$quote);
    }
}

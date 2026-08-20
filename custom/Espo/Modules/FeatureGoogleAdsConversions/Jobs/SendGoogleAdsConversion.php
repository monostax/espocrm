<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureGoogleAdsConversions\Jobs;

use Espo\Core\Job\Job;
use Espo\Core\Job\Job\Data;
use Espo\Core\Utils\Log;
use Espo\Modules\FeatureGoogleAdsConversions\Services\GoogleAdsDispatcher;

class SendGoogleAdsConversion implements Job
{
    public function __construct(
        private GoogleAdsDispatcher $dispatcher,
        private Log $log,
    ) {}

    public function run(Data $data): void
    {
        $uploadId = $data->get('uploadId');

        if (!is_string($uploadId) || $uploadId === '') {
            $this->log->warning('SendGoogleAdsConversion: missing uploadId in job data.');
            return;
        }

        $this->dispatcher->dispatch($uploadId);
    }
}

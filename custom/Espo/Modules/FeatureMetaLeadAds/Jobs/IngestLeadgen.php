<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureMetaLeadAds\Jobs;

use Espo\Core\Job\Job;
use Espo\Core\Job\Job\Data;
use Espo\Core\Utils\Log;
use Espo\Modules\FeatureMetaLeadAds\Services\LeadgenIngester;

/**
 * Async wrapper around LeadgenIngester::ingest.
 *
 * Payload:
 *   eventId — string id of the MetaLeadgenEvent row to process.
 *
 * The event row is always created synchronously by `MetaLeadAdsWebhook` before
 * the job is scheduled. The webhook returns 200 immediately and Meta does
 * not retry; if the job fails the failure is captured in the event row
 * (status=Failed) and visible in the CRM UI.
 *
 * Group is `meta-leadgen-{leadgenId}` to keep duplicate webhook deliveries
 * processed sequentially (defensive — the ingester is also idempotent).
 */
class IngestLeadgen implements Job
{
    public function __construct(
        private LeadgenIngester $ingester,
        private Log $log,
    ) {}

    public function run(Data $data): void
    {
        $eventId = $data->get('eventId');

        if (!is_string($eventId) || $eventId === '') {
            $this->log->warning('MetaLeadAds IngestLeadgen: missing eventId in job data.');

            return;
        }

        $this->ingester->ingest($eventId);
    }
}

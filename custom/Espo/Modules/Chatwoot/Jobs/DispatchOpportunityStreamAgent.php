<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Jobs;

use Espo\Core\Job\Job;
use Espo\Core\Job\Job\Data;
use GuzzleHttp\Client;
use RuntimeException;

/** Retryable, signed CRM → backend notification; no model work runs in the CRM request. */
class DispatchOpportunityStreamAgent implements Job
{
    public function run(Data $data): void
    {
        $url = getenv('CRM_OPPORTUNITY_STREAM_AGENT_WEBHOOK_URL');
        $secret = getenv('CRM_OPPORTUNITY_STREAM_AGENT_WEBHOOK_SECRET');
        if (!$url || !$secret) {
            throw new RuntimeException('Opportunity stream agent webhook URL and secret must be configured.');
        }
        $payload = [];
        foreach (['noteId', 'opportunityId', 'postHash', 'aiAgentMembershipId', 'chatwootAccountCrmId', 'crmTenantId'] as $key) {
            $payload[$key] = $data->get($key);
        }
        $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $timestamp = (string) time();
        $response = (new Client())->post($url, [
            'body' => $body,
            'headers' => [
                'Content-Type' => 'application/json',
                'X-Crm-Timestamp' => $timestamp,
                'X-Crm-Signature' => 'sha256=' . hash_hmac('sha256', "$timestamp.$body", $secret),
            ],
            'connect_timeout' => 5,
            'timeout' => 15,
            'allow_redirects' => false,
        ]);
        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            throw new RuntimeException('Opportunity stream agent webhook was not accepted.');
        }
    }
}

<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureIntegrationCalCom\Controllers;

use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\InjectableFactory;
use Espo\Core\Utils\Log;
use Espo\Modules\FeatureIntegrationCalCom\Services\CalComBookingProcessor;
use Espo\Modules\FeatureIntegrationCalCom\Services\ProcessResult;
use stdClass;
use Throwable;

/**
 * Public webhook endpoint for cal.com.
 *
 * Route (noAuth):  POST /CalCom/receive/:apiKey
 *
 * Headers expected from cal.com:
 *   - Content-Type: application/json
 *   - X-Cal-Signature-256: hex HMAC-SHA256(rawBody, signingSecret)  [if integration has secret]
 *
 * Response codes:
 *   200 OK            -> processed or intentionally skipped (cal.com won't retry)
 *   400 Bad Request   -> malformed payload
 *   401 Unauthorized  -> bad/missing signature
 *   404 Not Found     -> unknown apiKey
 *   500 Internal      -> unexpected (cal.com WILL retry)
 *
 * We intentionally translate every known failure to a clean 2xx/4xx with a JSON
 * body. Only truly unexpected exceptions surface as 500 so cal.com retries.
 */
class CalComWebhook
{
    public function __construct(
        private InjectableFactory $injectableFactory,
        private Log $log,
    ) {}

    public function postActionReceive(Request $request, Response $response): stdClass
    {
        $response->setHeader('Content-Type', 'application/json');

        $apiKey = (string) ($request->getRouteParam('apiKey') ?? '');

        try {
            $rawBody = (string) $request->getBodyContents();

            if ($rawBody === '') {
                $response->setStatus(400);

                return (object) [
                    'ok'      => false,
                    'message' => 'Empty body.',
                ];
            }

            $payload = json_decode($rawBody);

            if (!$payload instanceof stdClass) {
                $response->setStatus(400);

                return (object) [
                    'ok'      => false,
                    'message' => 'Body is not a JSON object.',
                ];
            }

            $headers = $this->collectHeaders($request);

            $result = $this->injectableFactory
                ->create(CalComBookingProcessor::class)
                ->process($apiKey, $payload, $rawBody, $headers);

            $response->setStatus($result->httpStatus);

            return (object) [
                'ok'      => in_array($result->status, [ProcessResult::ACCEPTED, ProcessResult::SKIPPED], true),
                'status'  => $result->status,
                'message' => $result->message,
                'details' => (object) $result->details,
            ];
        } catch (Throwable $e) {
            $this->log->error('CalComWebhook: ' . $e->getMessage(), [
                'apiKey' => $apiKey,
                'trace'  => $e->getTraceAsString(),
            ]);

            $response->setStatus(500);

            return (object) [
                'ok'      => false,
                'message' => 'Internal error.',
            ];
        }
    }

    /**
     * @return array<string, string>  Lower-cased header map.
     */
    private function collectHeaders(Request $request): array
    {
        $names = [
            'X-Cal-Signature-256',
            'X-Cal-Webhook-Id',
            'X-Cal-Trigger-Event',
            'User-Agent',
            'Content-Type',
        ];

        $out = [];

        foreach ($names as $name) {
            $value = $request->getHeader($name);
            if ($value !== null) {
                $out[strtolower($name)] = $value;
            }
        }

        return $out;
    }
}

<?php

namespace Espo\Modules\Chatwoot\Controllers;

use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Conflict;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Utils\Log;
use Espo\Modules\Chatwoot\Services\ManagedCampaignBridge;
use Espo\Modules\Chatwoot\Services\ManagedCampaignImport;

class ManagedWhatsAppCampaign
{
    public function __construct(private ManagedCampaignBridge $bridge, private ManagedCampaignImport $imports, private Log $log) {}

    public function postActionExecute(Request $request, Response $response): object
    {
        try {
            $body = $request->getBodyContents() ?? '';
            $platform = $this->bridge->authenticate($request, $body);
            if (strlen($body) > 2000000) throw new BadRequest('Invalid campaign request.');
            $data = json_decode($body, true);
            if (!is_array($data)) throw new BadRequest('Invalid campaign request.');
            return (object) $this->imports->dispatch($platform, $data);
        } catch (\Throwable $e) {
            // Core API debug errors include raw input. This endpoint must never log imported personal data.
            $status = match (true) {
                $e instanceof BadRequest => 400, $e instanceof Forbidden => 403,
                $e instanceof NotFound => 404, $e instanceof Conflict => 409, default => 503,
            };
            $code = $e instanceof Conflict && in_array($e->getMessage(), ['preparation_already_active', 'request_conflict'], true)
                ? $e->getMessage() : 'campaign_request_failed';
            $response->setStatus($status);
            $this->log->warning('Managed campaign request failed.', ['stage' => 'bridge', 'status' => $status, 'code' => $code]);
            return (object) ['error' => $code];
        }
    }
}

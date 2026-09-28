<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureAiUsage\Controllers;

use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Modules\FeatureAiUsage\Services\Service;

class AiUsage
{
    public function __construct(private Service $service) {}

    public function getActionContext(Request $request, Response $response): object
    {
        $response->setHeader('Cache-Control', 'private, no-store');
        return (object) $this->service->context();
    }

    public function getActionSummary(Request $request, Response $response): object
    {
        $response->setHeader('Cache-Control', 'private, no-store');
        return (object) $this->service->summary($request->getQueryParams());
    }

    public function getActionDetail(Request $request, Response $response): object
    {
        $response->setHeader('Cache-Control', 'private, no-store');
        return (object) $this->service->detail((string) $request->getRouteParam('id'), $request->getQueryParams());
    }
}

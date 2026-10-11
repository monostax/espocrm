<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Controllers;

use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Modules\FeatureAiUsage\Services\Access;

class Credits
{
    public function __construct(private Access $access) {}

    public function getActionContext(Request $request, Response $response): object
    {
        $response->setHeader('Cache-Control', 'private, no-store');
        return (object) ['tenants' => $this->access->tenants()];
    }
}

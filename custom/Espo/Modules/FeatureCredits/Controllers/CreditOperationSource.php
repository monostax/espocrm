<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Controllers;

use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Exceptions\BadRequest;
use Espo\Modules\FeatureAiUsage\Services\Access;
use Espo\Modules\FeatureCredits\Services\OperationSource;

class CreditOperationSource
{
    public function __construct(private Access $access, private OperationSource $source) {}

    public function getActionRead(Request $request, Response $response): object
    {
        $response->setHeader('Cache-Control', 'private, no-store');
        $params = $request->getQueryParams();
        $tenant = $params['tenantId'] ?? null;
        $id = $params['id'] ?? null;
        foreach ([$tenant, $id] as $value) {
            if (!is_string($value) || !preg_match('/^[a-zA-Z0-9_-]{1,24}$/D', $value)) {
                throw new BadRequest('Invalid credit operation source query.');
            }
        }
        $this->access->assertTenant($tenant);
        return (object) $this->source->inspect($tenant, $id);
    }
}

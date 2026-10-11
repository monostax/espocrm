<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Controllers;

use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Exceptions\BadRequest;
use Espo\Modules\FeatureAiUsage\Services\Access;
use Espo\Modules\FeatureCredits\Accounting\RequestHistory;
use Espo\Modules\FeatureCredits\Accounting\RequestHistoryQuery;
use InvalidArgumentException;

class CreditRequests
{
    public function __construct(private Access $access, private RequestHistory $requests) {}

    public function getActionList(Request $request, Response $response): object
    {
        $response->setHeader('Cache-Control', 'private, no-store');
        $params = $request->getQueryParams();
        $tenant = $params['tenantId'] ?? null;
        $limit = $params['limit'] ?? '50';
        $cursor = $params['cursor'] ?? null;
        if (!is_string($tenant) || !is_string($limit) || !preg_match('/^[1-9][0-9]{0,2}$/D', $limit) ||
            (array_key_exists('cursor', $params) && !is_string($cursor))) {
            throw new BadRequest('Invalid credit requests query.');
        }
        try {
            $input = new RequestHistoryQuery($tenant, (int) $limit, $cursor);
        } catch (InvalidArgumentException) {
            throw new BadRequest('Invalid credit requests query.');
        }
        $this->access->assertTenant($tenant);
        return (object) $this->requests->inspect($input);
    }
}

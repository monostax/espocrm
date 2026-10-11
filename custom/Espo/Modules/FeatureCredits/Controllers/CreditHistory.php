<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Controllers;

use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Exceptions\BadRequest;
use Espo\Modules\FeatureAiUsage\Services\Access;
use Espo\Modules\FeatureCredits\Accounting\TransactionHistory;
use Espo\Modules\FeatureCredits\Accounting\TransactionHistoryQuery;
use InvalidArgumentException;

class CreditHistory
{
    public function __construct(private Access $access, private TransactionHistory $history) {}

    public function getActionList(Request $request, Response $response): object
    {
        $response->setHeader('Cache-Control', 'private, no-store');
        $params = $request->getQueryParams();
        $tenant = $params['tenantId'] ?? null;
        $limit = $params['limit'] ?? '50';
        $cursor = $params['cursor'] ?? null;
        if (!is_string($tenant) || !is_string($limit) || !preg_match('/^[1-9][0-9]{0,2}$/D', $limit) ||
            (array_key_exists('cursor', $params) && !is_string($cursor))) {
            throw new BadRequest('Invalid credit history query.');
        }
        try {
            $input = new TransactionHistoryQuery($tenant, (int) $limit, $cursor);
        } catch (InvalidArgumentException) {
            throw new BadRequest('Invalid credit history query.');
        }
        $this->access->assertTenant($tenant);
        return (object) $this->history->inspect($input);
    }
}

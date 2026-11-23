<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Controllers;

use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Exceptions\BadRequest;
use Espo\Modules\FeatureAiUsage\Services\Access;
use Espo\Modules\FeatureCredits\Accounting\UsageSummary;
use Espo\Modules\FeatureCredits\Accounting\UsageSummaryQuery;
use InvalidArgumentException;

/** Financial administrator analytics API: category spend, unit economics, and user breakdown. */
class CreditSummary
{
    public function __construct(private Access $access, private UsageSummary $summary) {}

    public function getActionRead(Request $request, Response $response): object
    {
        $response->setHeader('Cache-Control', 'private, no-store');
        $params = $request->getQueryParams();
        $tenant = $params['tenantId'] ?? null;
        $from = $params['from'] ?? null;
        $to = $params['to'] ?? null;
        $tzRaw = $params['tzOffsetMinutes'] ?? '0';

        if (!is_string($tenant) || !is_string($from) || !is_string($to) ||
            !is_string($tzRaw) || !preg_match('/^-?(?:0|[1-9][0-9]{0,2})$/D', $tzRaw)) {
            throw new BadRequest('Invalid credit summary query.');
        }

        try {
            $input = new UsageSummaryQuery($tenant, $from, $to, (int) $tzRaw);
        } catch (InvalidArgumentException) {
            throw new BadRequest('Invalid credit summary query.');
        }

        $this->access->assertTenant($tenant);
        return (object) $this->summary->inspect($input);
    }
}

<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Controllers;

use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Exceptions\BadRequest;
use Espo\Modules\FeatureAiUsage\Services\Access;
use Espo\Modules\FeatureCredits\Accounting\ReservationHistory;
use Espo\Modules\FeatureCredits\Accounting\ReservationHistoryQuery;
use InvalidArgumentException;

class CreditReservations
{
    public function __construct(private Access $access, private ReservationHistory $reservations) {}

    public function getActionList(Request $request, Response $response): object
    {
        $response->setHeader('Cache-Control', 'private, no-store');
        $params = $request->getQueryParams();
        $tenant = $params['tenantId'] ?? null;
        $limit = $params['limit'] ?? '50';
        $cursor = $params['cursor'] ?? null;
        if (!is_string($tenant) || !is_string($limit) || !preg_match('/^[1-9][0-9]{0,2}$/D', $limit) ||
            (array_key_exists('cursor', $params) && !is_string($cursor))) {
            throw new BadRequest('Invalid credit reservations query.');
        }
        try {
            $input = new ReservationHistoryQuery($tenant, (int) $limit, $cursor);
        } catch (InvalidArgumentException) {
            throw new BadRequest('Invalid credit reservations query.');
        }
        $this->access->assertTenant($tenant);
        return (object) $this->reservations->inspect($input);
    }
}

<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Controllers;

use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Exceptions\BadRequest;
use Espo\Modules\FeatureAiUsage\Services\Access;
use Espo\Modules\FeatureCredits\Accounting\BalanceStatus;
use Espo\Modules\FeatureCredits\Accounting\GrantInput;
use InvalidArgumentException;

/** Financial administrator read API; balance is not execution authorization. */
class CreditBalance
{
    public function __construct(private Access $access, private BalanceStatus $status) {}

    public function getActionStatus(Request $request, Response $response): object
    {
        $response->setHeader('Cache-Control', 'private, no-store');
        $tenantId = $request->getQueryParams()['tenantId'] ?? null;
        if (!is_string($tenantId)) {
            throw new BadRequest('An explicit tenantId is required.');
        }
        try {
            GrantInput::identity($tenantId);
        } catch (InvalidArgumentException) {
            throw new BadRequest('Invalid tenantId.');
        }
        // Reuse the established billing administrator policy, including team-scoped roles.
        $this->access->assertTenant($tenantId);
        return (object) $this->status->inspect($tenantId);
    }
}

<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Controllers;

use Espo\Core\Api\Request;
use Espo\Modules\FeatureCredits\Billing\Asaas\Events;

final class CreditAsaasEvents
{
    public function __construct(private Events $events) {}

    public function postActionReceive(Request $request): object
    {
        return (object) $this->events->receive($request->getRouteParam('integrationId') ?? '',
            $request->getHeader('asaas-access-token') ?? '', $request->getBodyContents() ?? '');
    }
}

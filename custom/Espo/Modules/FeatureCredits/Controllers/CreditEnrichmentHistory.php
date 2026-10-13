<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Controllers;

use Espo\Core\Api\{Request,Response};
use Espo\Core\Exceptions\BadRequest;
use Espo\Modules\FeatureCredits\Enrichment\PurchasedHistory;
use InvalidArgumentException;

final class CreditEnrichmentHistory
{
    public function __construct(private PurchasedHistory $history) {}

    public function getActionList(Request $request,Response $response): object
    {
        $response->setHeader('Cache-Control','private, no-store');
        try {
            return (object) $this->history->read($request->getQueryParams());
        } catch (InvalidArgumentException $e) {
            throw new BadRequest($e->getMessage());
        }
    }
}

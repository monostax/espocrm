<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Controllers;

use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Exceptions\BadRequest;
use Espo\Modules\FeatureCredits\Enrichment\Quotes;
use InvalidArgumentException;

final class CreditEnrichmentPreview
{
    public function __construct(private Quotes $quotes) {}

    public function getActionRead(Request $request, Response $response): object
    {
        $response->setHeader('Cache-Control', 'private, no-store');
        try {
            return (object) $this->quotes->preview($request->getQueryParams());
        } catch (InvalidArgumentException $e) {
            throw new BadRequest($e->getMessage());
        }
    }
}

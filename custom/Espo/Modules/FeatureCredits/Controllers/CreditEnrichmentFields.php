<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Controllers;

use Espo\Core\Api\{Request,Response};
use Espo\Core\Exceptions\BadRequest;
use Espo\Modules\FeatureCredits\Enrichment\FieldApplication;
use Espo\Modules\FeatureCredits\Enrichment\CustomerProjection;
use InvalidArgumentException;

final class CreditEnrichmentFields
{
    public function __construct(private FieldApplication $application) {}

    public function postActionApply(Request $request,Response $response): object
    {
        $response->setHeader('Cache-Control','private, no-store');
        $data = $request->getParsedBody();
        if (!$data instanceof \stdClass) throw new BadRequest('An application object is required.');
        try { return (object) CustomerProjection::application($this->application->apply($data)); }
        catch (InvalidArgumentException $e) { throw new BadRequest($e->getMessage()); }
    }
}

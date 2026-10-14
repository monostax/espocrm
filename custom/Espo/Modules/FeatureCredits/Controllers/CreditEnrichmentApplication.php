<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Controllers;

use Espo\Core\Api\{Request,Response};
use Espo\Core\Exceptions\BadRequest;
use Espo\Modules\FeatureCredits\Enrichment\{ApplicationInput,CandidateApplication,CustomerProjection};
use InvalidArgumentException;
use JsonException;
use stdClass;

final class CreditEnrichmentApplication
{
    public function __construct(private CandidateApplication $application) {}
    public function postActionApply(Request $request,Response $response): object
    {
        $response->setHeader('Cache-Control','private, no-store');
        $body = $request->getBodyContents() ?? '';
        if (strlen($body) > 16384) throw new BadRequest('Application command is too large.');
        try {
            $data = json_decode($body,false,8,JSON_THROW_ON_ERROR);
            if (!$data instanceof stdClass) throw new InvalidArgumentException('Application command required.');
            return (object) CustomerProjection::application($this->application->apply(new ApplicationInput($data)));
        } catch (InvalidArgumentException|JsonException $e) {
            throw new BadRequest($e->getMessage());
        }
    }
}

<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Controllers;

use Espo\Core\Api\{Request, Response};
use Espo\Core\Exceptions\BadRequest;
use Espo\Modules\FeatureCredits\Verification\Batches;

final class CreditVerificationBatches
{
    public function __construct(private Batches $batches) {}

    public function postActionCreate(Request $request, Response $response): object
    {
        $response->setHeader('Cache-Control','private, no-store');
        $raw = $request->getBodyContents() ?? '';
        if (strlen($raw)>131072 || !($input=json_decode($raw,false,8)) instanceof \stdClass) throw new BadRequest();
        return (object)$this->batches->create($input);
    }

    public function getActionCurrent(Request $request, Response $response): object
    {
        $response->setHeader('Cache-Control','private, no-store');
        return (object)['batch'=>$this->batches->current($request->getQueryParams())];
    }
}

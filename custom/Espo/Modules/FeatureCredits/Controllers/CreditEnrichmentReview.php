<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Controllers;

use Espo\Core\Api\{Request,Response};
use Espo\Core\Exceptions\BadRequest;
use Espo\Modules\FeatureCredits\Enrichment\PurchasedReview;
use Espo\Modules\FeatureCredits\Enrichment\FieldReview;
use InvalidArgumentException;

final class CreditEnrichmentReview
{
    public function __construct(private PurchasedReview $review,private FieldReview $fields) {}

    public function getActionRead(Request $request,Response $response): object
    {
        $response->setHeader('Cache-Control','private, no-store');
        $params = $request->getQueryParams();
        if (array_diff(array_keys($params),['tenantId','id','source']) || !is_string($params['tenantId'] ?? null) || !is_string($params['id'] ?? null) ||
            !in_array($params['source'] ?? 'discovery',['discovery','field'],true)) {
            throw new BadRequest('Only tenantId, purchased id and optional field/discovery source are accepted.');
        }
        try {
            return (object) (($params['source'] ?? 'discovery') === 'field' ?
                $this->fields->read($params['tenantId'],$params['id']) : $this->review->read($params['tenantId'],$params['id']));
        } catch (InvalidArgumentException $e) {
            throw new BadRequest($e->getMessage());
        }
    }
}

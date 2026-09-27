<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCatchUp\Controllers;

use Espo\Core\Api\Request;
use Espo\Modules\FeatureCatchUp\Services\Feed;
use Espo\Modules\FeatureCatchUp\Services\Summary;

/** Operation API; deliberately exposes no generic entity CRUD. */
class CatchUp
{
    public function __construct(private Feed $feed, private Summary $summary) {}

    public function getActionList(Request $request): object
    {
        return (object) $this->feed->list($request);
    }

    public function getActionRead(Request $request): object
    {
        return (object) $this->feed->snapshot((string) $request->getRouteParam('id'));
    }

    public function postActionReview(Request $request): object
    {
        $this->feed->review((string) $request->getRouteParam('id'), (string) ($request->getParsedBody()->snapshot ?? ''));
        return (object) ['success' => true];
    }

    public function postActionSummary(Request $request): object
    {
        $snapshot = $this->feed->snapshot((string) $request->getRouteParam('id'));
        $locale = $request->getParsedBody()->locale ?? 'en';
        $requestId = $request->getParsedBody()->requestId ?? null;
        if ($requestId !== null && (!is_string($requestId) || !preg_match('/^[a-f0-9]{64}$/D', $requestId))) {
            throw new \Espo\Core\Exceptions\BadRequest('Invalid summary request ID.');
        }
        return (object) [
            ...$this->summary->request($snapshot, $locale === 'pt_BR' || $locale === 'pt' ? 'pt-BR' : 'en', $requestId),
            'snapshot' => $snapshot,
        ];
    }
}

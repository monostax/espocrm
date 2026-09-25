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
        return (object) [
            'summary' => $this->summary->generate($snapshot, $locale === 'pt_BR' || $locale === 'pt' ? 'pt-BR' : 'en'),
            'snapshot' => $snapshot,
        ];
    }
}

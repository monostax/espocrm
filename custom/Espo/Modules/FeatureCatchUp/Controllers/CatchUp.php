<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCatchUp\Controllers;

use Espo\Core\Api\Request;
use Espo\Core\Exceptions\BadRequest;
use Espo\Modules\FeatureCatchUp\Services\Actions;
use Espo\Modules\FeatureCatchUp\Services\Feed;
use Espo\Modules\FeatureCatchUp\Services\Snoozes;
use Espo\Modules\FeatureCatchUp\Services\Summary;
use Espo\Modules\FeatureCatchUp\Services\Workspace;

/** Operation API; deliberately exposes no generic entity CRUD. */
class CatchUp
{
    public function __construct(
        private Feed $feed,
        private Summary $summary,
        private Actions $actions,
        private Snoozes $snoozes,
        private Workspace $workspace,
    ) {}

    public function getActionList(Request $request): object
    {
        return (object) $this->feed->list($request);
    }

    public function getActionCount(Request $request): object
    {
        return (object) $this->feed->count($request);
    }

    public function getActionRead(Request $request): object
    {
        return (object) $this->feed->snapshot($this->id($request), $request);
    }

    public function postActionReview(Request $request): object
    {
        $this->feed->review($this->id($request), (string) ($request->getParsedBody()->snapshot ?? ''), $request);
        return (object) ['success' => true];
    }

    public function postActionSummary(Request $request): object
    {
        $body = $request->getParsedBody();
        $locale = $body->locale ?? 'en';
        $requestId = $body->requestId ?? null;
        if ($requestId !== null && (!is_string($requestId) || !preg_match('/^[a-f0-9]{64}$/D', $requestId))) {
            throw new BadRequest('Invalid summary request ID.');
        }
        $snapshot = $this->feed->snapshot($this->id($request), $request);
        return (object) [
            ...$this->summary->analyse($snapshot, $locale === 'pt_BR' || $locale === 'pt' ? 'pt-BR' : 'en', $requestId),
            'snapshot' => $snapshot,
        ];
    }

    public function postActionClose(Request $request): object
    {
        return (object) $this->actions->close($request, $this->id($request));
    }

    public function postActionResolvedElsewhere(Request $request): object
    {
        return (object) $this->actions->resolvedElsewhere($request, $this->id($request));
    }

    public function postActionSnooze(Request $request): object
    {
        $body = $request->getParsedBody();
        $tenant = $this->workspace->resolve($request)['tenant'];
        return (object) $this->snoozes->snooze($tenant, (string) ($body->type ?? ''), (string) ($body->id ?? ''), (string) ($body->until ?? ''));
    }

    private function id(Request $request): string
    {
        return (string) $request->getRouteParam('id');
    }
}

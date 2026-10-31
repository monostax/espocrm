<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCatchUp\Controllers;

use Espo\Core\Api\Request;
use Espo\Core\Exceptions\BadRequest;
use Espo\Modules\FeatureCatchUp\Services\Feed;
use Espo\Modules\FeatureCatchUp\Services\Snoozes;
use Espo\Modules\FeatureCatchUp\Services\Summary;
use Espo\Modules\FeatureCatchUp\Services\TaskFeed;
use Espo\Modules\FeatureCatchUp\Services\Workspace;

/** Operation API; deliberately exposes no generic entity CRUD. */
class CatchUp
{
    public function __construct(
        private Feed $feed,
        private TaskFeed $tasks,
        private Summary $summary,
        private Snoozes $snoozes,
        private Workspace $workspace,
    ) {}

    public function getActionList(Request $request): object
    {
        return (object) ($request->getQueryParam('source') === 'task' ? $this->tasks->list($request) : $this->feed->list($request));
    }

    public function getActionRead(Request $request): object
    {
        return (object) $this->feed->snapshot((string) $request->getRouteParam('id'), Workspace::now($request));
    }

    public function postActionReview(Request $request): object
    {
        $this->feed->review((string) $request->getRouteParam('id'), (string) ($request->getParsedBody()->snapshot ?? ''));
        return (object) ['success' => true];
    }

    public function postActionSummary(Request $request): object
    {
        return $this->summarize($request, $this->feed->snapshot((string) $request->getRouteParam('id'), Workspace::now($request)));
    }

    public function getActionReadTask(Request $request): object
    {
        return (object) $this->tasks->snapshot($request, (string) $request->getRouteParam('id'));
    }

    public function postActionSummaryTask(Request $request): object
    {
        return $this->summarize($request, $this->tasks->snapshot($request, (string) $request->getRouteParam('id')));
    }

    public function postActionCompleteTask(Request $request): object
    {
        return (object) $this->tasks->complete($request, (string) $request->getRouteParam('id'));
    }

    public function postActionRescheduleTask(Request $request): object
    {
        return (object) $this->tasks->reschedule($request, (string) $request->getRouteParam('id'));
    }

    public function postActionSnooze(Request $request): object
    {
        $body = $request->getParsedBody();
        $tenant = $this->workspace->resolve($request)['tenant'];
        return (object) $this->snoozes->snooze($tenant, (string) ($body->type ?? ''), (string) ($body->id ?? ''), (string) ($body->until ?? ''));
    }

    private function summarize(Request $request, array $snapshot): object
    {
        $locale = $request->getParsedBody()->locale ?? 'en';
        $requestId = $request->getParsedBody()->requestId ?? null;
        if ($requestId !== null && (!is_string($requestId) || !preg_match('/^[a-f0-9]{64}$/D', $requestId))) {
            throw new BadRequest('Invalid summary request ID.');
        }
        return (object) [
            ...$this->summary->request($snapshot, $locale === 'pt_BR' || $locale === 'pt' ? 'pt-BR' : 'en', $requestId),
            'snapshot' => $snapshot,
        ];
    }
}

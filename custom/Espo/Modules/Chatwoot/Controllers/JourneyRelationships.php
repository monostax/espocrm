<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Controllers;

use Espo\Core\Api\Request;
use Espo\Core\Exceptions\BadRequest;
use Espo\Modules\Chatwoot\Services\JourneyRelationships as Service;

class JourneyRelationships
{
    public function __construct(private Service $service) {}

    private function context(Request $request): array
    {
        $accountId = filter_var($request->getQueryParam('accountId'), FILTER_VALIDATE_INT);
        if ($accountId === false || $accountId < 1) throw new BadRequest('Invalid workspace ID.');
        return $this->service->context(
            $accountId,
            (string) $request->getRouteParam('type'),
            (string) $request->getRouteParam('targetId'),
        );
    }

    private function offset(Request $request): int
    {
        $offset = filter_var($request->getQueryParam('offset') ?? 0, FILTER_VALIDATE_INT);
        if ($offset === false || $offset < 0) throw new BadRequest('Invalid offset.');
        return $offset;
    }

    public function getActionList(Request $request): object
    {
        [$tenant, $target] = $this->context($request);
        return $this->service->list($tenant, $target, $this->offset($request));
    }

    public function getActionOptions(Request $request): object
    {
        [$tenant, $target] = $this->context($request);
        $search = $request->getQueryParam('search') ?? '';
        if (!is_string($search)) throw new BadRequest('Invalid search.');
        return $this->service->options($tenant, $target, trim($search), $this->offset($request));
    }

    public function getActionJourneys(Request $request): object
    {
        $accountId = filter_var($request->getQueryParam('accountId'), FILTER_VALIDATE_INT);
        if ($accountId === false || $accountId < 1) throw new BadRequest('Invalid workspace ID.');
        $search = $request->getQueryParam('search') ?? '';
        if (!is_string($search)) throw new BadRequest('Invalid search.');
        return $this->service->journeys(
            $accountId,
            (string) $request->getRouteParam('type'),
            trim($search),
            $this->offset($request),
            $request->getQueryParam('purpose') === 'exit',
        );
    }

    public function deleteActionExitJourney(Request $request): object
    {
        [$tenant, $target] = $this->context($request);
        $journeyId = $request->getQueryParam('journeyId');
        if (!is_string($journeyId) || $journeyId === '') throw new BadRequest('Missing Journey ID.');
        return $this->service->exitJourney($tenant, $target, $journeyId);
    }

    public function postActionEnroll(Request $request): object
    {
        [$tenant, $target] = $this->context($request);
        $body = $request->getParsedBody();
        if (!is_object($body) || !isset($body->journeyId) || !is_string($body->journeyId) || $body->journeyId === '') {
            throw new BadRequest('Missing Journey ID.');
        }
        return $this->service->enroll($tenant, $target, $body->journeyId);
    }

    public function getActionRead(Request $request): object
    {
        [$tenant, $target] = $this->context($request);
        return $this->service->read($tenant, $target, (string) $request->getRouteParam('id'));
    }

    public function postActionPause(Request $request): object
    {
        [$tenant, $target] = $this->context($request);
        return $this->service->pause($tenant, $target, (string) $request->getRouteParam('id'));
    }

    public function postActionResume(Request $request): object
    {
        [$tenant, $target] = $this->context($request);
        return $this->service->resume($tenant, $target, (string) $request->getRouteParam('id'));
    }

    public function deleteActionUnlink(Request $request): object
    {
        [$tenant, $target] = $this->context($request);
        return $this->service->unlink($tenant, $target, (string) $request->getRouteParam('id'));
    }

    public function deleteActionDelete(Request $request): object
    {
        [$tenant, $target] = $this->context($request);
        return $this->service->delete($tenant, $target, (string) $request->getRouteParam('id'));
    }

    public function getActionReplyReview(Request $request): object
    {
        [$tenant, $target] = $this->context($request);
        return $this->service->replyReview($tenant, $target, (string) $request->getRouteParam('id'),
            $request->getQueryParam('view') !== 'contacts');
    }

    public function postActionReplyReview(Request $request): object
    {
        [$tenant, $target] = $this->context($request);
        $body = $request->getParsedBody();
        return $this->service->decideReplyReview(
            $tenant,
            $target,
            (string) $request->getRouteParam('id'),
            is_object($body) ? $body : (object) [],
            $request->getQueryParam('view') !== 'contacts',
        );
    }
}

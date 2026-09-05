<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Controllers;

use Espo\Core\Api\Request;
use Espo\Core\Exceptions\BadRequest;
use Espo\Modules\Chatwoot\Services\OpportunityReadStateService;

// Do not inherit generic CRUD endpoints: this is private, per-user state.
class OpportunityReadState
{
    public function __construct(private OpportunityReadStateService $service) {}

    public function getActionReadState(Request $request): object
    {
        return (object) $this->service->getReadState($this->id($request));
    }

    public function postActionMarkRead(Request $request): object
    {
        $body = $request->getParsedBody();
        $postId = $body->lastPostId ?? null;
        $version = $body->expectedVersion ?? null;
        if (($postId !== null && (!is_string($postId) || !preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $postId))) ||
            ($version !== null && (!is_int($version) || $version < 0)) || isset($body->lastSeenAt)) {
            throw new BadRequest('Use a post ID and a non-negative read-state version, not a client timestamp.');
        }
        return (object) $this->service->markRead($this->id($request), $postId, $version);
    }

    public function postActionMarkUnread(Request $request): object
    {
        return (object) $this->service->markUnread($this->id($request));
    }

    public function postActionReadStates(Request $request): object
    {
        $ids = $request->getParsedBody()->ids ?? [];
        if (!is_array($ids)) {
            throw new BadRequest('IDs must be an array.');
        }
        return (object) $this->service->getReadStates($ids);
    }

    public function getActionReadStates(Request $request): object
    {
        $ids = $request->getQueryParam('ids');
        return (object) $this->service->getReadStates(is_array($ids) ? $ids : ($ids ? explode(',', $ids) : []));
    }

    private function id(Request $request): string
    {
        $id = $request->getRouteParam('id');
        if (!$id) {
            throw new BadRequest('Opportunity ID is required.');
        }
        return $id;
    }
}

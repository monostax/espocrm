<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Controllers;

use Espo\Core\Api\Request;
use Espo\Core\Exceptions\BadRequest;
use Espo\Modules\Chatwoot\Services\OpportunityStreamAgent as Service;

class OpportunityStreamAgent
{
    public function __construct(private Service $service) {}

    public function getActionContext(Request $request): object
    {
        return $this->service->context(
            $this->id($request, 'id'),
            $this->id($request, 'membershipId'),
            $this->postHash($request->getQueryParam('postHash')),
        );
    }

    public function postActionReply(Request $request): object
    {
        $body = $request->getParsedBody();
        $post = $body->post ?? null;
        $runId = $body->workflowRunId ?? null;
        if (!is_string($post) || trim($post) === '' || mb_strlen($post) > 20000 ||
            !is_string($runId) || !preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $runId)) {
            throw new BadRequest('A response (up to 20000 characters) and workflow run ID are required.');
        }
        return $this->service->reply(
            $this->id($request, 'id'),
            $this->id($request, 'membershipId'),
            $this->postHash($body->postHash ?? null),
            trim($post),
            $runId,
        );
    }

    public function postActionClaim(Request $request): object
    {
        $body = $request->getParsedBody();
        $runId = $body->workflowRunId ?? null;
        if (!is_string($runId) || !preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $runId)) {
            throw new BadRequest('A workflow run ID is required.');
        }
        return $this->service->claim(
            $this->id($request, 'id'), $this->id($request, 'membershipId'),
            $this->postHash($body->postHash ?? null), $runId,
        );
    }

    private function id(Request $request, string $key): string
    {
        $id = $request->getRouteParam($key);
        if (!$id || !preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $id)) {
            throw new BadRequest('Valid note and membership IDs are required.');
        }
        return $id;
    }

    private function postHash(mixed $hash): string
    {
        if (!is_string($hash) || !preg_match('/^[a-f0-9]{64}$/D', $hash)) {
            throw new BadRequest('The original post hash is required.');
        }
        return $hash;
    }
}

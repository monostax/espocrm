<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Controllers;

use Espo\Core\Api\Request;
use Espo\Core\Exceptions\BadRequest;
use Espo\Modules\Chatwoot\Services\StreamAgent as Service;

class StreamAgent
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

    public function postActionStatus(Request $request): object
    {
        $body = $request->getParsedBody();
        $runId = $body->workflowRunId ?? null;
        $status = $body->status ?? null;
        if (!is_string($runId) || !preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $runId) ||
            !in_array($status, ['failed', 'cancelled', 'blocked'], true)) {
            throw new BadRequest('A workflow run ID and terminal status are required.');
        }
        return $this->service->status($this->id($request, 'id'), $this->id($request, 'membershipId'), $runId, $status);
    }

    public function getActionAttachments(Request $request): object
    {
        $runId = $request->getQueryParam('workflowRunId');
        $postId = $request->getQueryParam('sourcePostId');
        $attachmentId = $request->getQueryParam('attachmentId');
        foreach ([$runId, $postId, ...($attachmentId !== null ? [$attachmentId] : [])] as $value) {
            if (!is_string($value) || !preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $value)) {
                throw new BadRequest('Valid run, source post and attachment IDs are required.');
            }
        }
        return $this->service->attachments($this->id($request, 'id'), $this->id($request, 'membershipId'),
            $this->postHash($request->getQueryParam('postHash')), $runId, $postId, $attachmentId);
    }

    public function postActionProgress(Request $request): object
    {
        $body = $request->getParsedBody();
        $runId = $body->workflowRunId ?? null;
        if (!is_string($runId) || !preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $runId) ||
            !is_int($body->sequence ?? null) || $body->sequence < 1 ||
            !in_array($body->phase ?? null, ['preparing', 'thinking', 'finalizing'], true) ||
            !is_array($body->activities ?? null) || count($body->activities) > 60) {
            throw new BadRequest('Invalid stream progress snapshot.');
        }
        $activities = [];
        $ids = [];
        foreach ($body->activities as $activity) {
            if (!is_object($activity) || !is_string($activity->id ?? null) ||
                !preg_match('/^[a-zA-Z0-9_-]{1,128}$/D', $activity->id) || isset($ids[$activity->id]) ||
                !in_array($activity->kind ?? null, ['search', 'read', 'create', 'update', 'delete', 'send', 'execute'], true) ||
                !in_array($activity->status ?? null, ['running', 'completed', 'failed', 'accepted', 'unknown'], true)) {
                throw new BadRequest('Invalid stream activity.');
            }
            $ids[$activity->id] = true;
            // Explicit projection prevents tool arguments, results or arbitrary text being persisted.
            $entry = (object) ['id' => $activity->id, 'kind' => $activity->kind, 'status' => $activity->status,
                'startedAt' => $this->timestamp($activity->startedAt ?? null)];
            if ($activity->status !== 'running') $entry->finishedAt = $this->timestamp($activity->finishedAt ?? null);
            $activities[] = $entry;
        }
        return $this->service->updateProgress($this->id($request, 'id'), $this->id($request, 'membershipId'), $runId,
            (object) ['sequence' => $body->sequence, 'phase' => $body->phase, 'activities' => $activities]);
    }

    private function timestamp(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/D', $value) ||
            strtotime($value) === false) throw new BadRequest('Invalid activity timestamp.');
        return $value;
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

<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Controllers;

use Espo\Core\Api\Request;
use Espo\Core\Exceptions\BadRequest;
use Espo\Modules\Chatwoot\Services\RecordFilters;

// Workspace-scoped API only, never inherit the generic record controller.
class RecordFilter
{
    public function __construct(private RecordFilters $filters) {}

    public function getActionList(Request $request): object
    {
        return $this->filters->list($this->accountId($request));
    }

    public function postActionCreate(Request $request): object
    {
        return $this->filters->create($this->accountId($request), $this->body($request));
    }

    public function putActionUpdate(Request $request): object
    {
        return $this->filters->update($this->accountId($request), (string) $request->getRouteParam('id'), $this->body($request));
    }

    public function deleteActionDelete(Request $request): bool
    {
        $this->filters->delete($this->accountId($request), (string) $request->getRouteParam('id'));
        return true;
    }

    private function body(Request $request): object
    {
        $body = $request->getParsedBody();
        if (!is_object($body)) throw new BadRequest();
        return $body;
    }

    private function accountId(Request $request): int
    {
        $id = filter_var($request->getRouteParam('accountId'), FILTER_VALIDATE_INT);
        if (!$id || $id < 1) throw new BadRequest('A workspace is required.');
        return $id;
    }
}

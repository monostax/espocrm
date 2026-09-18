<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Controllers;

use Espo\Core\Api\Request;
use Espo\Core\Exceptions\BadRequest;
use Espo\Modules\Chatwoot\Services\OpportunityBulkPost as Service;

// Private operation API, never inherit the generic record controller.
class OpportunityBulkPost
{
    public function __construct(private Service $service) {}

    public function postActionSubmit(Request $request): object
    {
        $body = $request->getParsedBody();
        if (!is_object($body)) throw new BadRequest();
        return $this->service->submit($this->accountId($request), $body);
    }

    public function getActionRecent(Request $request): object
    {
        return $this->service->recent($this->accountId($request));
    }

    public function getActionStatus(Request $request): object
    {
        return $this->service->status($this->accountId($request), (string) $request->getRouteParam('id'));
    }

    public function getActionResults(Request $request): object
    {
        $after = $request->getQueryParam('after');
        if ($after !== null && (!is_string($after) || !preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $after))) {
            throw new BadRequest('Invalid result cursor.');
        }
        return $this->service->results($this->accountId($request), (string) $request->getRouteParam('id'), $after);
    }

    public function postActionRetry(Request $request): object
    {
        return $this->service->retry($this->accountId($request), (string) $request->getRouteParam('id'));
    }

    private function accountId(Request $request): int
    {
        $value = (string) $request->getRouteParam('accountId');
        if (!ctype_digit($value) || (int) $value < 1) throw new BadRequest('Invalid workspace.');
        return (int) $value;
    }
}

<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Controllers;

use Espo\Core\Api\Request;
use Espo\Core\Exceptions\BadRequest;
use Espo\Modules\Chatwoot\Services\OpportunityFollowupEvents as Service;

class OpportunityFollowupEvents
{
    public function __construct(private Service $service) {}

    /** The backend reports one AI follow-up message sent on the deal's conversation (account and conversation as Chat shows them). */
    public function postActionSent(Request $request): object
    {
        $id = $request->getRouteParam('id');
        $body = $request->getParsedBody();
        $ids = [$body->chatwootAccountId ?? null, $body->chatwootConversationId ?? null, $body->chatwootMessageId ?? null];
        if (!is_string($id) || $id === '' || strlen($id) > 24 || array_filter($ids, fn ($v) => !is_int($v) || $v < 1)) {
            throw new BadRequest('Invalid follow-up event.');
        }
        return $this->service->sent($id, ...$ids);
    }

    /** The backend stopped a due AI follow-up that could not reach the customer (official WhatsApp number past its 24h window). */
    public function postActionNotSent(Request $request): object
    {
        $id = $request->getRouteParam('id');
        $body = $request->getParsedBody();
        $reason = $body->reason ?? null;
        $key = $body->key ?? null;
        $closedAt = $body->windowClosedAt ?? null;
        $ids = [$body->chatwootAccountId ?? null, $body->chatwootConversationId ?? null];
        if (!is_string($id) || $id === '' || strlen($id) > 24 || !in_array($reason, Service::NOT_SENT_REASONS, true) ||
            !is_string($key) || $key === '' || strlen($key) > 200 ||
            ($closedAt !== null && (!is_string($closedAt) || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $closedAt))) ||
            array_filter($ids, fn ($v) => !is_int($v) || $v < 1)) {
            throw new BadRequest('Invalid follow-up event.');
        }
        return $this->service->notSent($id, $reason, $closedAt, $key, ...$ids);
    }
}

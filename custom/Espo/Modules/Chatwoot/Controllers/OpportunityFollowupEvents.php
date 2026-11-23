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
}

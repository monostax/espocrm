<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Controllers;

use Espo\Core\Api\Request;
use Espo\Core\Exceptions\BadRequest;
use Espo\Modules\Chatwoot\Services\OpportunityWatcher as Service;

class OpportunityWatcher
{
    public function __construct(private Service $service) {}

    public function postActionDecision(Request $request): object
    {
        $id = $request->getRouteParam('id');
        $body = $request->getParsedBody();
        $text = fn ($value, int $max) => is_string($value) && $value !== '' && mb_strlen($value) <= $max;
        $optional = fn ($value, int $max) => $value === null || $text($value, $max);
        $probability = $body->probability ?? null;
        $conversationId = $body->conversationId ?? null;
        $window = $body->messageWindow ?? null;
        if (!$text($id, 24) ||
            !in_array($body->outcome ?? null, Service::OUTCOMES, true) ||
            !$text($body->runId ?? null, 64) || !preg_match('/^[a-zA-Z0-9_:-]+$/D', $body->runId) ||
            !$text($body->model ?? null, 100) ||
            !$optional($body->expectedStageId ?? null, 24) || !$optional($body->toStageId ?? null, 24) ||
            !$optional($body->reason ?? null, 1000) ||
            ($probability !== null && (!is_numeric($probability) || $probability < 0 || $probability > 1)) ||
            ($conversationId !== null && (!is_int($conversationId) || $conversationId < 1)) ||
            ($window !== null && (!is_object($window) || !$optional($window->from ?? null, 40) || !$optional($window->to ?? null, 40))) ||
            (($body->details ?? null) !== null && (!is_object($body->details) || strlen(json_encode($body->details)) > 8000))) {
            throw new BadRequest('Invalid watcher decision.');
        }
        if ($body->outcome === Service::MOVE && (!$text($body->expectedStageId ?? null, 24) ||
            !$text($body->toStageId ?? null, 24) || !$text($body->reason ?? null, 1000) || $probability === null)) {
            throw new BadRequest('A move needs the expected stage, the target stage, a reason and a probability.');
        }
        return $this->service->decide($id, $body);
    }
}

<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureAiSession\Controllers;

use Espo\Core\Api\Request;
use Espo\Modules\FeatureAiSession\Services\AgentInstructions;

/** An AI agent's instructions for the AI chat: read (ACL), change and restore (tenant admins). */
class AiAgentInstructions
{
    public function __construct(private AgentInstructions $instructions) {}

    public function getActionRead(Request $request): object
    {
        return $this->instructions->read((string) $request->getRouteParam('id'));
    }

    public function postActionWrite(Request $request): object
    {
        $body = $request->getParsedBody();
        return $this->instructions->write((string) $request->getRouteParam('id'), (string) ($body->text ?? ''), (string) ($body->expectedHash ?? ''));
    }

    public function postActionRestore(Request $request): object
    {
        return $this->instructions->restore((string) $request->getRouteParam('id'), (string) ($request->getParsedBody()->revisionId ?? ''));
    }
}

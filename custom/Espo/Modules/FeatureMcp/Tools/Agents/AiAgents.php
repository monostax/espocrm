<?php

declare(strict_types=1);

/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

namespace Espo\Modules\FeatureMcp\Tools\Agents;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Conflict;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Modules\FeatureAiSession\Services\AgentInstructions;
use Espo\Modules\FeatureMcp\Tools\Knowledge\Replacements;

/**
 * AI agents and their instructions (system prompt) over MCP, through the AI Session's service: anyone who can open
 * the agent reads them; only tenant admins change them; a change made meanwhile is a conflict (hash); every change is
 * a revision that can be restored.
 */
class AiAgents
{
    public const int READ_CHARS = 12000;

    public function __construct(
        private AgentDirectory $directory,
        private AgentInstructions $instructions,
    ) {}

    public function list(): array
    {
        return ['agents' => array_map(fn (array $agent) => [
            'agentId' => $agent['id'],
            'name' => $agent['name'],
            'workspace' => $agent['workspace'],
            'knowledgeCategoryIds' => $agent['categoryIds'],
        ], $this->directory->list())];
    }

    /** @throws NotFound|Forbidden */
    public function read(string $agentId, int $offset): array
    {
        $agent = $this->instructions->read($agentId);
        $total = mb_strlen($agent->text);

        return [
            'agentId' => $agent->id,
            'name' => $agent->name,
            'instructions' => mb_substr($agent->text, $offset, self::READ_CHARS),
            'totalChars' => $total,
        ] + ($offset + self::READ_CHARS < $total ? ['nextOffset' => $offset + self::READ_CHARS] : []) + [
            'hash' => $agent->hash,
            'canEdit' => $agent->canEdit,
        ] + ($agent->canEdit
            ? ['changes' => $agent->changes]
            : ['note' => 'Only tenant admins can change these instructions.']);
    }

    /**
     * @param ?list<object> $replacements
     * @throws NotFound|Forbidden|Conflict|BadRequest
     */
    public function update(string $agentId, string $hash, ?array $replacements, ?string $text): array
    {
        $agent = $this->instructions->read($agentId);

        if (!hash_equals($agent->hash, $hash)) {
            return ['status' => 'changed_meanwhile',
                'explanation' => 'The instructions changed since they were read. Read them again, then retry.'];
        }

        $changes = [];

        if ($replacements) {
            $outcome = Replacements::apply($agent->text, $replacements);

            if (!$outcome['ok']) {
                unset($outcome['ok']);

                return ['status' => 'not_applied'] + $outcome;
            }

            $text = $outcome['text'];
            $changes = $outcome['changes'];
        }

        if ($text === null) {
            return ['status' => 'not_applied', 'problem' => 'nothing_to_change',
                'explanation' => 'Pass replacements or the whole new text.'];
        }

        $result = $this->instructions->write($agentId, $text, $hash);

        return [
            'status' => ($result->changed ?? false) ? 'updated' : 'unchanged',
            'agentId' => $agentId,
            'name' => $agent->name,
            'hash' => $result->hash,
        ] + ($changes ? ['textChanges' => $changes] : []) + (($result->revisionId ?? null)
            ? ['undo' => ['restoreRevisionId' => $result->revisionId, 'how' => 'To undo, pass it to AiAgentsEdit.']]
            : []);
    }

    /** @throws NotFound|Forbidden */
    public function restore(string $agentId, string $revisionId): array
    {
        $result = $this->instructions->restore($agentId, $revisionId);

        return [
            'status' => ($result->changed ?? false) ? 'restored' : 'already_same',
            'agentId' => $agentId,
            'hash' => $result->hash,
        ];
    }
}

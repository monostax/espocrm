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

use Espo\Core\Acl;
use Espo\Modules\FeatureMcp\Tools\Feature\AiAgentsData;
use Espo\Modules\FeatureMcp\Tools\Knowledge\KnowledgeBaseToolDefinitionProvider;
use Espo\Modules\Mcp\Tools\Feature\Data;
use Espo\Modules\Mcp\Tools\Feature\Exceptions\NoUserAccess;
use Espo\Modules\Mcp\Tools\Feature\ToolDefinitionProvider;
use Espo\Modules\Mcp\Tools\JsonSchema\Type\IntegerType;
use Espo\Modules\Mcp\Tools\JsonSchema\Type\ObjectType;
use Espo\Modules\Mcp\Tools\JsonSchema\Type\StringType;
use Espo\Modules\Mcp\Tools\Mcp\Schema\General\RootObjectSchema;
use Espo\Modules\Mcp\Tools\Mcp\Schema\General\RootSchema;
use Espo\Modules\Mcp\Tools\Mcp\Schema\Tool\Tool;
use Espo\Modules\Mcp\Tools\Mcp\Schema\Tool\ToolAnnotations;

class AiAgentsToolDefinitionProvider implements ToolDefinitionProvider
{
    public const string NAME = AiAgentsData::TYPE;

    private const string DESCRIPTION =
        'The Monostax AI agents the signed-in user can see (the AI that answers customers), with their workspace ' .
        'and the knowledge categories they search. `agentId`: the agent\'s instructions (its system prompt) as ' .
        'stored, in parts via `offset`, the `hash` to pass when changing them, whether the user may (tenant admins ' .
        'only) and recent changes.';

    public function __construct(
        private Acl $acl,
    ) {}

    public function get(Data $data): Tool
    {
        if (!$this->acl->checkScope('ChatwootAccountUserMembership', Acl\Table::ACTION_READ)) {
            throw new NoUserAccess("No access to AI agents.");
        }

        return new Tool(
            name: self::NAME,
            inputSchema: new RootObjectSchema(new ObjectType(
                properties: [
                    'agentId' => new StringType(pattern: KnowledgeBaseToolDefinitionProvider::ID_PATTERN, description: 'The agent to read.'),
                    'offset' => new IntegerType(minimum: 0, description: 'Where to read on (nextOffset).'),
                ],
                additionalProperties: false,
            )),
            outputSchema: new RootSchema(new ObjectType(additionalProperties: true)),
            description: self::DESCRIPTION,
            annotations: new ToolAnnotations(readOnlyHint: true, idempotentHint: true),
        );
    }
}

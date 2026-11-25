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

use Espo\Entities\User;
use Espo\Modules\FeatureMcp\Tools\Feature\AiAgentsEditData;
use Espo\Modules\FeatureMcp\Tools\Knowledge\KnowledgeBaseToolDefinitionProvider;
use Espo\Modules\Global\Classes\Utils\TenantRoleAuth;
use Espo\Modules\Mcp\Tools\Feature\Data;
use Espo\Modules\Mcp\Tools\Feature\Exceptions\NoUserAccess;
use Espo\Modules\Mcp\Tools\Feature\ToolDefinitionProvider;
use Espo\Modules\Mcp\Tools\JsonSchema\Type\ArrayType;
use Espo\Modules\Mcp\Tools\JsonSchema\Type\BooleanType;
use Espo\Modules\Mcp\Tools\JsonSchema\Type\ObjectType;
use Espo\Modules\Mcp\Tools\JsonSchema\Type\StringType;
use Espo\Modules\Mcp\Tools\Mcp\Schema\General\RootObjectSchema;
use Espo\Modules\Mcp\Tools\Mcp\Schema\General\RootSchema;
use Espo\Modules\Mcp\Tools\Mcp\Schema\Tool\Tool;
use Espo\Modules\Mcp\Tools\Mcp\Schema\Tool\ToolAnnotations;
use Espo\ORM\EntityManager;

/** `AiAgentsEdit`: only listed for tenant admins (the CRM refuses everyone else anyway). */
class AiAgentsEditToolDefinitionProvider implements ToolDefinitionProvider
{
    public const string NAME = AiAgentsEditData::TYPE;

    private const string DESCRIPTION =
        'Changes an AI agent\'s instructions (its system prompt); tenant admins only. Every answer the agent gives ' .
        'follows them, so change only what was asked. `agentId` + `hash` (from AiAgents) + `replacements` (exact ' .
        'passages copied from the stored text) or `text` (the whole new instructions). Undo: `agentId` + ' .
        '`restoreRevisionId` (from a result or AiAgents changes).';

    public function __construct(
        private User $user,
        private EntityManager $entityManager,
    ) {}

    public function get(Data $data): Tool
    {
        // Same gate as AgentInstructions::canEdit, which instance admins without the role also fail.
        if (!TenantRoleAuth::hasTenantAdminRole($this->user, $this->entityManager)) {
            throw new NoUserAccess("Only tenant admins change AI agent instructions.");
        }

        return new Tool(
            name: self::NAME,
            inputSchema: new RootObjectSchema(new ObjectType(
                properties: [
                    'agentId' => new StringType(pattern: KnowledgeBaseToolDefinitionProvider::ID_PATTERN),
                    'hash' => new StringType(pattern: '^[a-f0-9]{64}$', description: 'hash from AiAgents.'),
                    'replacements' => new ArrayType(
                        items: new ObjectType(
                            properties: [
                                'find' => new StringType(minLength: 1, maxLength: 8000, description: 'Exact passage as stored, long enough to be unique.'),
                                'replace' => new StringType(maxLength: 8000),
                                'all' => new BooleanType(description: 'Replace every occurrence.'),
                            ],
                            required: ['find', 'replace'],
                            additionalProperties: false,
                        ),
                        maxItems: 20,
                    ),
                    'text' => new StringType(minLength: 1, maxLength: 200000, description: 'The whole new instructions.'),
                    'restoreRevisionId' => new StringType(pattern: KnowledgeBaseToolDefinitionProvider::ID_PATTERN, description: 'Undo: the change to restore.'),
                ],
                required: ['agentId'],
                additionalProperties: false,
            )),
            outputSchema: new RootSchema(new ObjectType(additionalProperties: true)),
            description: self::DESCRIPTION,
            annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false),
        );
    }
}

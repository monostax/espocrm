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

namespace Espo\Modules\FeatureMcp\Tools\Chat;

use Espo\Modules\FeatureMcp\Tools\Feature\ConversationNotesData;
use Espo\Modules\Mcp\Tools\Feature\Data;
use Espo\Modules\Mcp\Tools\Feature\ToolDefinitionProvider;
use Espo\Modules\Mcp\Tools\JsonSchema\EnumSchema;
use Espo\Modules\Mcp\Tools\JsonSchema\Type\ArrayType;
use Espo\Modules\Mcp\Tools\JsonSchema\Type\BooleanType;
use Espo\Modules\Mcp\Tools\JsonSchema\Type\IntegerType;
use Espo\Modules\Mcp\Tools\JsonSchema\Type\ObjectType;
use Espo\Modules\Mcp\Tools\JsonSchema\Type\StringType;
use Espo\Modules\Mcp\Tools\Mcp\Schema\General\RootObjectSchema;
use Espo\Modules\Mcp\Tools\Mcp\Schema\General\RootSchema;
use Espo\Modules\Mcp\Tools\Mcp\Schema\Tool\Tool;
use Espo\Modules\Mcp\Tools\Mcp\Schema\Tool\ToolAnnotations;

class ConversationNotesToolDefinitionProvider implements ToolDefinitionProvider
{
    public const string NAME = ConversationNotesData::TYPE;

    private const string DESCRIPTION =
        'Adds a private note to a Chat conversation as the signed-in user: only the team sees it, never the customer. ' .
        '`mentionUserIds` notifies teammates (ids from Conversations lookup agents). The note shows "via" this app.';

    public function __construct(
        private ChatTools $tools,
    ) {}

    public function get(Data $data): Tool
    {
        return new Tool(
            name: self::NAME,
            inputSchema: new RootObjectSchema(new ObjectType(
                properties: [
                    'workspace' => $this->tools->workspaceSchema(),
                    'conversationId' => new IntegerType(minimum: 1),
                    'text' => new StringType(minLength: 1, maxLength: ConversationWriter::MAX_TEXT),
                    'mentionUserIds' => new ArrayType(items: new IntegerType(minimum: 1), maxItems: 10, uniqueItems: true),
                ],
                required: ['conversationId', 'text'],
                additionalProperties: false,
            )),
            outputSchema: new RootSchema(new ObjectType(additionalProperties: true)),
            description: self::DESCRIPTION,
            annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false),
        );
    }
}

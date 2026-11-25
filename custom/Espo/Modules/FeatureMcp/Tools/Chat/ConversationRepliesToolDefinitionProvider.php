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

use Espo\Modules\FeatureMcp\Tools\Feature\ConversationRepliesData;
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

/** Writes to customers: marked open-world and destructive (it cannot be unsent) so MCP clients ask before each send. */
class ConversationRepliesToolDefinitionProvider implements ToolDefinitionProvider
{
    public const string NAME = ConversationRepliesData::TYPE;

    private const string DESCRIPTION =
        'Sends a message to the customer in a Chat conversation, as the signed-in user, on the conversation\'s ' .
        'channel (WhatsApp, Instagram, email…). It cannot be unsent: send only what the person asked for, with their ' .
        'words or approval. `text` while the channel accepts free text; on WhatsApp after 24 hours without a customer ' .
        'message only a `template` goes (list them with Conversations lookup templates; give its body, header and ' .
        'button parameters). The message shows "via" this app.';

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
                    'template' => new ObjectType(
                        properties: [
                            'name' => new StringType(minLength: 1, maxLength: 512),
                            'language' => new StringType(maxLength: 20, description: 'Needed when the template exists in several languages.'),
                            'body' => new ObjectType(additionalProperties: new StringType(maxLength: 1024), description: 'Body parameters by key: {"1": "Ana"} or {"customer_name": "Ana"}.'),
                            'header' => new ObjectType(additionalProperties: new StringType(maxLength: 2048), description: 'Header text parameters by key, or {"mediaUrl": "https://…"} for a media header.'),
                            'buttons' => new ArrayType(items: new StringType(maxLength: 2000), maxItems: 10, description: 'By button index: the URL suffix or coupon code; empty for buttons without a parameter.'),
                        ],
                        required: ['name'],
                        additionalProperties: false,
                    ),
                ],
                required: ['conversationId'],
                additionalProperties: false,
            )),
            outputSchema: new RootSchema(new ObjectType(additionalProperties: true)),
            description: self::DESCRIPTION,
            annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: true, openWorldHint: true),
        );
    }
}

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

use Espo\Modules\FeatureMcp\Tools\Feature\ConversationsData;
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

class ConversationsToolDefinitionProvider implements ToolDefinitionProvider
{
    public const string NAME = ConversationsData::TYPE;

    private const string DESCRIPTION =
        'Reads Chat (WhatsApp, Instagram, email and the other channels) as the signed-in user: only what they can ' .
        'open in Chat. No arguments: open conversations (filter with status, assignee, inboxId, label). `q`: search ' .
        'message text, last 3 months. `contact`: a contact\'s conversations by name, phone or email. `conversationId`: ' .
        'the conversation and its transcript (older messages with `before`; one long message whole with `messageId` ' .
        '+ `offset`). `lookup`: agents, teams, labels, inboxes, or an inbox\'s WhatsApp templates (with `inboxId` or ' .
        '`conversationId`).';

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
                    'q' => new StringType(minLength: 2, maxLength: 200, description: 'Words as written in the messages.'),
                    'contact' => new StringType(minLength: 2, maxLength: 120, description: 'Name, phone (with country code) or email.'),
                    'status' => new EnumSchema(values: ['open', 'pending', 'snoozed', 'resolved', 'all']),
                    'assignee' => new EnumSchema(values: ['me', 'unassigned', 'all']),
                    'inboxId' => new IntegerType(minimum: 1),
                    'label' => new StringType(maxLength: 100),
                    'page' => new IntegerType(minimum: 1, maximum: 50),
                    'conversationId' => new IntegerType(minimum: 1, description: 'Conversation number, as Chat shows it.'),
                    'before' => new IntegerType(minimum: 1, description: 'Older messages before this message id.'),
                    'messageId' => new IntegerType(minimum: 1, description: 'Read this one message whole.'),
                    'offset' => new IntegerType(minimum: 0, description: 'With messageId: where to read on.'),
                    'lookup' => new EnumSchema(values: ['agents', 'teams', 'labels', 'inboxes', 'templates']),
                ],
                additionalProperties: false,
            )),
            outputSchema: new RootSchema(new ObjectType(additionalProperties: true)),
            description: self::DESCRIPTION,
            annotations: new ToolAnnotations(readOnlyHint: true, idempotentHint: true),
        );
    }
}

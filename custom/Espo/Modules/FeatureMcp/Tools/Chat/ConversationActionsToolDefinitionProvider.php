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

use Espo\Modules\FeatureMcp\Tools\Feature\ConversationActionsData;
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

class ConversationActionsToolDefinitionProvider implements ToolDefinitionProvider
{
    public const string NAME = ConversationActionsData::TYPE;

    private const string DESCRIPTION =
        'Changes a Chat conversation as the signed-in user: `status` (open, pending, resolved, snoozed with ' .
        '`snoozedUntil`), `assigneeId` or `unassign`, `teamId`, labels (`labels` replaces them; `addLabels` / ' .
        '`removeLabels` change some), `priority`. Ids come from Conversations lookup. The result says what each ' .
        'was before, to undo.';

    public function __construct(
        private ChatTools $tools,
    ) {}

    public function get(Data $data): Tool
    {
        $labels = fn (string $description) => new ArrayType(items: new StringType(maxLength: 100), maxItems: 30, uniqueItems: true, description: $description);

        return new Tool(
            name: self::NAME,
            inputSchema: new RootObjectSchema(new ObjectType(
                properties: [
                    'workspace' => $this->tools->workspaceSchema(),
                    'conversationId' => new IntegerType(minimum: 1),
                    'status' => new EnumSchema(values: ['open', 'pending', 'resolved', 'snoozed']),
                    'snoozedUntil' => new StringType(maxLength: 40, description: 'With status snoozed: date and time, ISO 8601 with offset.'),
                    'assigneeId' => new IntegerType(minimum: 1),
                    'unassign' => new BooleanType(),
                    'teamId' => new IntegerType(minimum: 0, description: '0 removes the team.'),
                    'labels' => $labels('Replaces all labels.'),
                    'addLabels' => $labels('Labels to add.'),
                    'removeLabels' => $labels('Labels to remove.'),
                    'priority' => new EnumSchema(values: ['urgent', 'high', 'medium', 'low', 'none']),
                ],
                required: ['conversationId'],
                additionalProperties: false,
            )),
            outputSchema: new RootSchema(new ObjectType(additionalProperties: true)),
            description: self::DESCRIPTION,
            annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false),
        );
    }
}

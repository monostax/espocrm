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

namespace Espo\Modules\FeatureMcp\Tools\Knowledge;

use Espo\Core\Acl;
use Espo\Modules\FeatureMcp\Tools\Feature\KnowledgeBaseEditData;
use Espo\Modules\Mcp\Tools\Feature\Data;
use Espo\Modules\Mcp\Tools\Feature\Exceptions\NoUserAccess;
use Espo\Modules\Mcp\Tools\Feature\ToolDefinitionProvider;
use Espo\Modules\Mcp\Tools\JsonSchema\EnumSchema;
use Espo\Modules\Mcp\Tools\JsonSchema\Schema;
use Espo\Modules\Mcp\Tools\JsonSchema\Type\ArrayType;
use Espo\Modules\Mcp\Tools\JsonSchema\Type\BooleanType;
use Espo\Modules\Mcp\Tools\JsonSchema\Type\IntegerType;
use Espo\Modules\Mcp\Tools\JsonSchema\Type\ObjectType;
use Espo\Modules\Mcp\Tools\JsonSchema\Type\StringType;
use Espo\Modules\Mcp\Tools\Mcp\Schema\General\RootObjectSchema;
use Espo\Modules\Mcp\Tools\Mcp\Schema\General\RootSchema;
use Espo\Modules\Mcp\Tools\Mcp\Schema\Tool\Tool;
use Espo\Modules\Mcp\Tools\Mcp\Schema\Tool\ToolAnnotations;

/**
 * `KnowledgeBaseEdit`: a separate tool from reading, so an MCP client can let reads run and ask before changes.
 * Changes apply at once as the user and can be undone (article revisions).
 */
class KnowledgeBaseEditToolDefinitionProvider implements ToolDefinitionProvider
{
    public const string NAME = KnowledgeBaseEditData::TYPE;

    private const string DESCRIPTION =
        'Changes the AI knowledge base as the signed-in user, at once; every change can be undone. Update: ' .
        '`articleId` + `version` (from KnowledgeBase) + `replacements` (exact passages copied from the stored text; ' .
        'its HTML or Markdown stays as is; change every place the old information appears), `title`, `status` or ' .
        '`categoryIds`. Undo: `articleId` + `restoreRevisionId` (from a result or KnowledgeBase history). New article ' .
        '(only when none covers it; search first): `title` + `text` (Markdown) + `categoryIds`. Afterwards tell the ' .
        'person what changed (before → after) and which agents use it.';

    public function __construct(
        private Acl $acl,
    ) {}

    public function get(Data $data): Tool
    {
        if (!$this->acl->checkScope('KnowledgeBaseArticle', Acl\Table::ACTION_EDIT)) {
            throw new NoUserAccess("No edit access to knowledge base articles.");
        }

        $id = fn (string $description): Schema => new StringType(
            pattern: KnowledgeBaseToolDefinitionProvider::ID_PATTERN,
            description: $description,
        );

        return new Tool(
            name: self::NAME,
            inputSchema: new RootObjectSchema(new ObjectType(
                properties: [
                    'articleId' => $id('Article to change or restore.'),
                    'version' => new IntegerType(minimum: 0, description: 'version from KnowledgeBase.'),
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
                    'title' => new StringType(minLength: 1, maxLength: 255),
                    'status' => new EnumSchema(values: ['Published', 'Draft', 'Archived'], description: 'Archived takes it out of the agents\' reach.'),
                    'categoryIds' => new ArrayType(items: $id('Category'), maxItems: 20, uniqueItems: true, description: 'Replaces the categories.'),
                    'text' => new StringType(minLength: 1, maxLength: 20000, description: 'New article only: its text, in Markdown.'),
                    'restoreRevisionId' => $id('Undo: the revision to restore.'),
                ],
                additionalProperties: false,
            )),
            outputSchema: new RootSchema(new ObjectType(additionalProperties: true)),
            description: self::DESCRIPTION,
            annotations: new ToolAnnotations(readOnlyHint: false, destructiveHint: false),
        );
    }
}

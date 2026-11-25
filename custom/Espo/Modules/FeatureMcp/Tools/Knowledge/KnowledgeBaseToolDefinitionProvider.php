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
use Espo\Modules\FeatureMcp\Tools\Feature\KnowledgeBaseData;
use Espo\Modules\Mcp\Tools\Feature\Data;
use Espo\Modules\Mcp\Tools\Feature\Exceptions\NoUserAccess;
use Espo\Modules\Mcp\Tools\Feature\ToolDefinitionProvider;
use Espo\Modules\Mcp\Tools\JsonSchema\Type\BooleanType;
use Espo\Modules\Mcp\Tools\JsonSchema\Type\IntegerType;
use Espo\Modules\Mcp\Tools\JsonSchema\Type\ObjectType;
use Espo\Modules\Mcp\Tools\JsonSchema\Type\StringType;
use Espo\Modules\Mcp\Tools\Mcp\Schema\General\RootObjectSchema;
use Espo\Modules\Mcp\Tools\Mcp\Schema\General\RootSchema;
use Espo\Modules\Mcp\Tools\Mcp\Schema\Tool\Tool;
use Espo\Modules\Mcp\Tools\Mcp\Schema\Tool\ToolAnnotations;

class KnowledgeBaseToolDefinitionProvider implements ToolDefinitionProvider
{
    public const string NAME = KnowledgeBaseData::TYPE;
    public const string ID_PATTERN = '^[A-Za-z0-9_-]{1,64}$';

    private const string DESCRIPTION =
        'Reads the AI knowledge base: the articles Monostax AI agents search to answer customers. No arguments: ' .
        'categories (AI search on or off, which agents use each), agents and article titles. `q`: articles whose ' .
        'title or text match, with the passage. `articleId`: the exact stored text (HTML or Markdown, in parts via ' .
        '`offset`), its version and which agents find it; add `history` for its recent changes. Read an article ' .
        'before changing it with KnowledgeBaseEdit.';

    public function __construct(
        private Acl $acl,
    ) {}

    public function get(Data $data): Tool
    {
        if (!$this->acl->checkScope('KnowledgeBaseArticle', Acl\Table::ACTION_READ)) {
            throw new NoUserAccess("No access to knowledge base articles.");
        }

        return new Tool(
            name: self::NAME,
            inputSchema: new RootObjectSchema(new ObjectType(
                properties: [
                    'q' => new StringType(minLength: 2, maxLength: 200, description: 'Words as written in the article.'),
                    'categoryId' => new StringType(pattern: self::ID_PATTERN, description: 'Only articles in this category.'),
                    'articleId' => new StringType(pattern: self::ID_PATTERN, description: 'The article to read.'),
                    'offset' => new IntegerType(minimum: 0, maximum: KnowledgeBase::MAX_BODY_CHARS, description: 'Where to read on (nextOffset).'),
                    'history' => new BooleanType(description: 'With articleId: list its recent changes instead.'),
                ],
                additionalProperties: false,
            )),
            outputSchema: new RootSchema(new ObjectType(additionalProperties: true)),
            description: self::DESCRIPTION,
            annotations: new ToolAnnotations(readOnlyHint: true, idempotentHint: true),
        );
    }
}

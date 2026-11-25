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

namespace Espo\Modules\FeatureMcp\Tools\Language;

use Espo\Modules\FeatureMcp\Tools\Feature\QueryLanguageData;
use Espo\Modules\FeatureMcp\Tools\Query\QueryableTypes;
use Espo\Modules\FeatureMcp\Tools\Query\QueryRecordsToolDefinitionProvider;
use Espo\Modules\Mcp\Tools\Feature\Data;
use Espo\Modules\Mcp\Tools\Feature\Exceptions\NoUserAccess;
use Espo\Modules\Mcp\Tools\Feature\ToolDefinitionProvider;
use Espo\Modules\Mcp\Tools\JsonSchema\EnumSchema;
use Espo\Modules\Mcp\Tools\JsonSchema\Type\ObjectType;
use Espo\Modules\Mcp\Tools\JsonSchema\Type\StringType;
use Espo\Modules\Mcp\Tools\Mcp\Schema\General\RootObjectSchema;
use Espo\Modules\Mcp\Tools\Mcp\Schema\General\RootSchema;
use Espo\Modules\Mcp\Tools\Mcp\Schema\Tool\Tool;
use Espo\Modules\Mcp\Tools\Mcp\Schema\Tool\ToolAnnotations;

/**
 * `QueryLanguage`: docs, discovery and checks for the advanced filter, read on demand so the tool list stays small
 * (the schema of one record type is about 4,000 characters, and related types nest).
 */
class QueryLanguageToolDefinitionProvider implements ToolDefinitionProvider
{
    /** ext-mcp finds a tool's feature type by the name's first `_` part, so the name is the type. */
    public const string NAME = QueryLanguageData::TYPE;

    private const string DESCRIPTION =
        'Docs for the advanced filter of QueryRecords. No arguments: the syntax and the record types. ' .
        '`entityType`: its fields (with labels and enum values), related records and lists; add `path` ' .
        '(e.g. `account`, `contacts.enrichmentResults`) to see a related type. `entityType` + `query`: checks ' .
        'the query without running it and says what is wrong and where.';

    public function __construct(
        private QueryableTypes $types,
    ) {}

    public function get(Data $data): Tool
    {
        $types = $this->types->readable();

        if (!$types) {
            throw new NoUserAccess("No record type the advanced filter covers is readable.");
        }

        return new Tool(
            name: self::NAME,
            inputSchema: new RootObjectSchema(new ObjectType(
                properties: [
                    'entityType' => new EnumSchema(values: $types, description: 'Record type the query filters.'),
                    'path' => new StringType(
                        maxLength: 200,
                        pattern: '^[A-Za-z][A-Za-z0-9_]*(\.[A-Za-z][A-Za-z0-9_]*)*$',
                        description: 'Related records to describe, as written in a query (`account`, `contacts`).',
                    ),
                    'query' => new StringType(
                        maxLength: QueryRecordsToolDefinitionProvider::MAX_QUERY_LENGTH,
                        description: 'A query to check.',
                    ),
                ],
                additionalProperties: false,
            )),
            outputSchema: new RootSchema(new ObjectType(additionalProperties: true)),
            description: self::DESCRIPTION,
            annotations: new ToolAnnotations(readOnlyHint: true, idempotentHint: true),
        );
    }
}

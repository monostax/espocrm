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

namespace Espo\Modules\FeatureMcp\Tools\Query;

use Espo\Modules\FeatureMcp\Tools\Feature\QueryRecordsData;
use Espo\Modules\Mcp\Tools\Feature\Data;
use Espo\Modules\Mcp\Tools\Feature\Exceptions\NoUserAccess;
use Espo\Modules\Mcp\Tools\Feature\ToolDefinitionProvider;
use Espo\Modules\Mcp\Tools\JsonSchema\EnumSchema;
use Espo\Modules\Mcp\Tools\JsonSchema\Type\ArrayType;
use Espo\Modules\Mcp\Tools\JsonSchema\Type\IntegerType;
use Espo\Modules\Mcp\Tools\JsonSchema\Type\ObjectType;
use Espo\Modules\Mcp\Tools\JsonSchema\Type\StringType;
use Espo\Modules\Mcp\Tools\Mcp\Schema\General\RootObjectSchema;
use Espo\Modules\Mcp\Tools\Mcp\Schema\General\RootSchema;
use Espo\Modules\Mcp\Tools\Mcp\Schema\Tool\Tool;
use Espo\Modules\Mcp\Tools\Mcp\Schema\Tool\ToolAnnotations;

/**
 * `QueryRecords`: one tool for every record type the advanced filter covers, with nothing configured per field. The
 * definition stays short; the language, the fields and query checks come from `QueryLanguage` on demand.
 */
class QueryRecordsToolDefinitionProvider implements ToolDefinitionProvider
{
    /** ext-mcp finds a tool's feature type by the name's first `_` part, so the name is the type. */
    public const string NAME = QueryRecordsData::TYPE;
    public const int DEFAULT_SIZE = 20;
    public const int MAX_SIZE = 100;
    public const int MAX_QUERY_LENGTH = 4000;

    private const string DESCRIPTION =
        'Finds Monostax records with the advanced filter, the query language of "Consulta avançada" in Monostax ' .
        'lists, e.g. `source = "Indicação" AND (account.industry IN ("SaaS", "Fintech") OR tags HAS "vip")`. ' .
        'Runs with the signed-in user\'s access. Call QueryLanguage for the syntax, the fields of each record ' .
        'type and to check a query.';

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
                    'entityType' => new EnumSchema(values: $types, description: 'Record type to search.'),
                    'query' => new StringType(
                        maxLength: self::MAX_QUERY_LENGTH,
                        description: 'Advanced filter. Field names are API names (see QueryLanguage).',
                    ),
                    'savedFilter' => new StringType(
                        maxLength: 64,
                        description: 'ID of a saved filter from SavedFilters. Combined with `query` by AND.',
                    ),
                    'search' => new StringType(
                        maxLength: 200,
                        description: 'Text search, like the search box of the list.',
                    ),
                    'select' => new ArrayType(
                        items: new StringType(maxLength: 100),
                        maxItems: 40,
                        uniqueItems: true,
                        description: 'Fields to return (id always comes). Default: the columns of the list.',
                    ),
                    'orderBy' => new StringType(maxLength: 100, description: 'Field to sort by.'),
                    'order' => new EnumSchema(values: ['asc', 'desc'], description: 'Sort direction.'),
                    'maxSize' => new IntegerType(
                        minimum: 1,
                        maximum: self::MAX_SIZE,
                        description: 'Records to return (default ' . self::DEFAULT_SIZE . ').',
                    ),
                    'offset' => new IntegerType(minimum: 0, description: 'Records to skip, for paging.'),
                ],
                required: ['entityType'],
                additionalProperties: false,
            )),
            outputSchema: new RootSchema(new ObjectType(
                properties: [
                    'records' => new ArrayType(items: new ObjectType(additionalProperties: true)),
                    'total' => new IntegerType(description: 'Records matching, across all pages.'),
                ],
                required: ['records', 'total'],
            )),
            description: self::DESCRIPTION,
            annotations: new ToolAnnotations(readOnlyHint: true, idempotentHint: true),
        );
    }
}

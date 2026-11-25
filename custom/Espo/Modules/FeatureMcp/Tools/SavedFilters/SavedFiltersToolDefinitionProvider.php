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

namespace Espo\Modules\FeatureMcp\Tools\SavedFilters;

use Espo\Modules\FeatureMcp\Tools\Feature\SavedFiltersData;
use Espo\Modules\FeatureMcp\Tools\Query\QueryableTypes;
use Espo\Modules\Mcp\Tools\Feature\Data;
use Espo\Modules\Mcp\Tools\Feature\Exceptions\NoUserAccess;
use Espo\Modules\Mcp\Tools\Feature\ToolDefinitionProvider;
use Espo\Modules\Mcp\Tools\JsonSchema\EnumSchema;
use Espo\Modules\Mcp\Tools\JsonSchema\Type\ArrayType;
use Espo\Modules\Mcp\Tools\JsonSchema\Type\BooleanType;
use Espo\Modules\Mcp\Tools\JsonSchema\Type\ObjectType;
use Espo\Modules\Mcp\Tools\JsonSchema\Type\StringType;
use Espo\Modules\Mcp\Tools\Mcp\Schema\General\RootObjectSchema;
use Espo\Modules\Mcp\Tools\Mcp\Schema\General\RootSchema;
use Espo\Modules\Mcp\Tools\Mcp\Schema\Tool\Tool;
use Espo\Modules\Mcp\Tools\Mcp\Schema\Tool\ToolAnnotations;

/**
 * `SavedFilters`: the user's saved filters (their own and the ones shared in their workspaces), read on each
 * call. Names never go into a tool definition: the tool list is cached for an hour and filters change any time.
 */
class SavedFiltersToolDefinitionProvider implements ToolDefinitionProvider
{
    /** ext-mcp finds a tool's feature type by the name's first `_` part, so the name is the type. */
    public const string NAME = SavedFiltersData::TYPE;

    private const string DESCRIPTION =
        'Lists the saved filters of Monostax lists that the signed-in user can use: their own and those shared in ' .
        'their workspaces. Pass a filter\'s id as `savedFilter` to QueryRecords to get its records.';

    public function __construct(
        private QueryableTypes $types,
    ) {}

    public function get(Data $data): Tool
    {
        $types = array_values(array_filter($this->types->readable(), fn ($type) => ConditionWhere::supports($type)));

        if (!$types) {
            throw new NoUserAccess("No record type with saved filters is readable.");
        }

        return new Tool(
            name: self::NAME,
            inputSchema: new RootObjectSchema(new ObjectType(
                properties: [
                    'entityType' => new EnumSchema(values: $types, description: 'Only filters of this record type.'),
                ],
                additionalProperties: false,
            )),
            outputSchema: new RootSchema(new ObjectType(
                properties: [
                    'filters' => new ArrayType(items: new ObjectType(
                        properties: [
                            'id' => new StringType(),
                            'name' => new StringType(),
                            'entityType' => new StringType(),
                            'workspace' => new StringType(),
                            'shared' => new BooleanType(),
                            'mine' => new BooleanType(),
                        ],
                    )),
                ],
                required: ['filters'],
            )),
            description: self::DESCRIPTION,
            annotations: new ToolAnnotations(readOnlyHint: true, idempotentHint: true),
        );
    }
}

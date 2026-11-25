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

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Record\ServiceFactory;
use Espo\Core\Select\SearchParams;
use Espo\Entities\User;
use Espo\Modules\Chatwoot\Tools\RecordQuery\QueryError;
use Espo\Modules\FeatureMcp\Tools\SavedFilters\SavedFilterAccess;
use Espo\Modules\Mcp\Tools\Feature\Data;
use Espo\Modules\Mcp\Tools\Mcp\Schema\General\RootSchema;
use Espo\Modules\Mcp\Tools\Mcp\Schema\Tool\CallToolRequestParams;
use Espo\Modules\Mcp\Tools\Mcp\Schema\Tool\CallToolResult;
use Espo\Modules\Mcp\Tools\Mcp\ToolsCall\ToolProcessor;
use stdClass;
use Throwable;

/**
 * Runs `QueryRecords` as any list request of the user: the record service built for them, with the query as the
 * `recordQuery` where item the Chatwoot lists send, so ACL, tenant isolation and the query's own limits apply and the
 * results match Monostax. A saved filter adds its conditions the way its list would.
 */
class QueryRecordsToolProcessor implements ToolProcessor
{
    public function __construct(
        private ServiceFactory $serviceFactory,
        private User $user,
        private QueryableTypes $types,
        private RecordFields $fields,
        private SavedFilterAccess $savedFilters,
        private UserTimeZone $timeZone,
        private StatementTimeout $timeout,
    ) {}

    public function process(CallToolRequestParams $params, Data $data, ?RootSchema $outputSchema): CallToolResult
    {
        $args = $params->arguments ?? new stdClass();
        $entityType = $args->entityType ?? null;

        if (!is_string($entityType) || !in_array($entityType, $this->types->readable(), true)) {
            return self::error(403, "No access to that record type.");
        }

        $timeZone = $this->timeZone->get();
        $where = [];

        $savedFilter = $args->savedFilter ?? null;

        if (is_string($savedFilter) && $savedFilter !== '') {
            try {
                array_push($where, ...$this->savedFilters->where($entityType, $savedFilter, $timeZone));
            } catch (NotFound) {
                return self::error(404, "Saved filter not found. List the ones you can use with SavedFilters.");
            }
        }

        $query = is_string($args->query ?? null) ? trim($args->query) : '';

        if ($query !== '') {
            $where[] = [
                'type' => QueryableTypes::WHERE_TYPE,
                'attribute' => 'id',
                'value' => ['query' => $query] + ($timeZone ? ['timeZone' => $timeZone] : []),
            ];
        }

        $select = is_array($args->select ?? null) ? $args->select : $this->fields->defaultSelect($entityType);
        $orderBy = is_string($args->orderBy ?? null) ? $args->orderBy : null;

        foreach ([...$select, ...($orderBy !== null ? [$orderBy] : [])] as $field) {
            if (!is_string($field) || !$this->fields->readable($entityType, $field)) {
                return self::error(400, "`$field` is not a field of $entityType you can read. " .
                    "List them with QueryLanguage (entityType: $entityType).");
            }
        }

        $raw = [
            'where' => $where,
            'select' => $this->fields->attributes($entityType, $select),
            'maxSize' => $args->maxSize ?? QueryRecordsToolDefinitionProvider::DEFAULT_SIZE,
            'offset' => $args->offset ?? 0,
        ];

        if ($orderBy !== null) {
            $raw['orderBy'] = $orderBy;
            $raw['order'] = ($args->order ?? 'asc') === 'desc' ? 'desc' : 'asc';
        }

        if (is_string($args->search ?? null) && trim($args->search) !== '') {
            $raw['textFilter'] = trim($args->search);
        }

        try {
            $collection = $this->timeout->run(
                fn () => $this->serviceFactory
                    ->createForUser($entityType, $this->user)
                    ->find(SearchParams::fromRaw($raw))
            );
        } catch (QueryError $e) {
            $error = QueryErrors::describe($e);

            return new CallToolResult(
                structuredContent: (object) ['error' => (object) [
                    'code' => 400,
                    'message' => "Query error at position $error->position: $error->message " .
                        "Check the query with QueryLanguage.",
                    'query' => $error,
                ]],
                isError: true,
            );
        } catch (Forbidden $e) {
            return self::error(403, trim("No access. " . $e->getMessage()));
        } catch (BadRequest $e) {
            return self::error(400, trim("Bad request. " . $e->getMessage()));
        } catch (Throwable $e) {
            if (StatementTimeout::isTimeout($e)) {
                return self::error(400, "The query took longer than " . StatementTimeout::SECONDS . " seconds. " .
                    "Narrow it, with fewer conditions on related lists, and try again.");
            }

            throw $e;
        }

        $attributes = $raw['select'];
        $records = [];

        foreach ($collection->getCollection() as $entity) {
            $values = $entity->getValueMap();
            $record = new stdClass();

            foreach ($attributes as $attribute) {
                if (property_exists($values, $attribute)) {
                    $record->$attribute = $values->$attribute;
                }
            }

            $records[] = $record;
        }

        return new CallToolResult(
            structuredContent: (object) [
                'records' => $records,
                'total' => $collection->getTotal(),
            ],
        );
    }

    private static function error(int $code, string $message): CallToolResult
    {
        return new CallToolResult(
            structuredContent: (object) ['error' => (object) ['code' => $code, 'message' => $message]],
            isError: true,
        );
    }
}

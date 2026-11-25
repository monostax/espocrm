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

use Espo\Core\Acl;
use Espo\Core\Utils\Language;
use Espo\Modules\Chatwoot\Tools\RecordQuery\Compiler;
use Espo\Modules\Chatwoot\Tools\RecordQuery\QueryError;
use Espo\Modules\Chatwoot\Tools\RecordQuery\Schema;
use Espo\Modules\FeatureMcp\Tools\Query\QueryableTypes;
use Espo\Modules\FeatureMcp\Tools\Query\QueryErrors;
use Espo\Modules\FeatureMcp\Tools\Query\StatementTimeout;
use Espo\Modules\FeatureMcp\Tools\Query\UserTimeZone;
use Espo\Modules\Mcp\Tools\Feature\Data;
use Espo\Modules\Mcp\Tools\Mcp\Schema\General\RootSchema;
use Espo\Modules\Mcp\Tools\Mcp\Schema\Tool\CallToolRequestParams;
use Espo\Modules\Mcp\Tools\Mcp\Schema\Tool\CallToolResult;
use Espo\Modules\Mcp\Tools\Mcp\ToolsCall\ToolProcessor;
use stdClass;

/**
 * Answers `QueryLanguage` from the same Schema (what the editor's autocomplete shows, per user and in their language)
 * and Compiler (what the editor's check runs) the "Consulta avançada" box uses, so the docs never drift from what a
 * query accepts.
 */
class QueryLanguageToolProcessor implements ToolProcessor
{
    public function __construct(
        private QueryableTypes $types,
        private Schema $schema,
        private Compiler $compiler,
        private Acl $acl,
        private Language $language,
        private UserTimeZone $timeZone,
        private StatementTimeout $timeout,
    ) {}

    public function process(CallToolRequestParams $params, Data $data, ?RootSchema $outputSchema): CallToolResult
    {
        $args = $params->arguments ?? new stdClass();
        $types = $this->types->readable();
        $entityType = $args->entityType ?? null;

        if ($entityType === null) {
            return self::result([
                'syntax' => Guide::LINES,
                'entityTypes' => array_map(fn (string $type) => [
                    'name' => $type,
                    'label' => $this->language->translateLabel($type, 'scopeNamesPlural'),
                ], $types),
                'next' => 'Call again with entityType to see its fields and related records.',
            ]);
        }

        if (!is_string($entityType) || !in_array($entityType, $types, true)) {
            return self::error("No access to that record type.");
        }

        if (is_string($args->query ?? null)) {
            return $this->check($entityType, $args->query);
        }

        return $this->describe($entityType, is_string($args->path ?? null) ? $args->path : '');
    }

    private function check(string $entityType, string $query): CallToolResult
    {
        try {
            $this->timeout->run(fn () => $this->compiler->compile($entityType, $query, $this->timeZone->get()));
        } catch (QueryError $e) {
            $error = QueryErrors::describe($e);
            $start = max(0, $error->position - 20);

            return self::result([
                'ok' => false,
                'error' => $error,
                'near' => mb_strcut($query, $start, 40),
            ]);
        }

        return self::result(['ok' => true]);
    }

    private function describe(string $entityType, string $path): CallToolResult
    {
        $node = $this->schema->describe($entityType);
        $type = $entityType;
        $walked = [];

        foreach ($path === '' ? [] : explode('.', $path) as $name) {
            $link = null;

            foreach ($node->links as $item) {
                if ($item['name'] === $name) {
                    $link = $item;
                }
            }

            $at = $walked ? implode('.', $walked) : $entityType;

            if (!$link) {
                return self::error("`$name` is not a relationship of $at you can filter by. " .
                    "Call without `path`" . ($walked ? " or with `" . implode('.', $walked) . "`" : '') .
                    " to see them.");
            }

            $walked[] = $name;
            $type = $link['entity'];

            if (isset($link['schema'])) {
                $node = (object) ($link['schema'] + ['fields' => [], 'links' => []]);

                continue;
            }

            if (!$this->acl->checkScope($type, Acl\Table::ACTION_READ)) {
                return self::error("`$name` is not a relationship of $at you can filter by.");
            }

            $node = $this->schema->describe($type);
        }

        return self::result([
            'entityType' => $entityType,
            'path' => $path,
            'describes' => $type,
            'fields' => array_map(fn (array $field) => array_filter([
                'name' => $field['name'],
                'kind' => $field['kind'],
                'label' => $field['label'],
                'options' => array_map(
                    fn (array $option) => $option['value'] === $option['label']
                        ? $option['value']
                        : $option['value'] . ' (' . $option['label'] . ')',
                    $field['options'] ?? []
                ) ?: null,
            ]), $node->fields),
            'one' => $this->links($node->links, false),
            'lists' => $this->links($node->links, true),
            'next' => 'Write `' . ($path === '' ? '' : $path . '.') . 'field` for one related record, ' .
                '`list.any(...)` for many; describe deeper with path.',
        ]);
    }

    /**
     * @param array<int, array<string, mixed>> $links
     * @return list<array<string, mixed>>
     */
    private function links(array $links, bool $many): array
    {
        $list = [];

        foreach ($links as $link) {
            if ((bool) ($link['many'] ?? false) !== $many) {
                continue;
            }

            $item = ['name' => $link['name'], 'entity' => $link['entity'], 'label' => $link['label']];

            foreach ($link['linkFields'] ?? [] as $field) {
                $item['linkFields'][] = $field['name'] . ': ' .
                    implode(' | ', array_map(fn (array $option) => $option['value'], $field['options'] ?? []));
            }

            $list[] = $item;
        }

        return $list;
    }

    /** @param array<string, mixed> $content */
    private static function result(array $content): CallToolResult
    {
        return new CallToolResult(structuredContent: json_decode((string) json_encode($content)));
    }

    private static function error(string $message): CallToolResult
    {
        return new CallToolResult(
            structuredContent: (object) ['error' => (object) ['code' => 400, 'message' => $message]],
            isError: true,
        );
    }
}

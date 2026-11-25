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

use Espo\Modules\FeatureMcp\Tools\Feature\ToolResult;
use Espo\Modules\Mcp\Tools\Feature\Data;
use Espo\Modules\Mcp\Tools\Mcp\Schema\General\RootSchema;
use Espo\Modules\Mcp\Tools\Mcp\Schema\Tool\CallToolRequestParams;
use Espo\Modules\Mcp\Tools\Mcp\Schema\Tool\CallToolResult;
use Espo\Modules\Mcp\Tools\Mcp\ToolsCall\ToolProcessor;
use stdClass;

class KnowledgeBaseEditToolProcessor implements ToolProcessor
{
    public function __construct(
        private KnowledgeBase $knowledgeBase,
    ) {}

    public function process(CallToolRequestParams $params, Data $data, ?RootSchema $outputSchema): CallToolResult
    {
        $args = $params->arguments ?? new stdClass();
        $articleId = is_string($args->articleId ?? null) ? $args->articleId : null;
        $restore = is_string($args->restoreRevisionId ?? null) ? $args->restoreRevisionId : null;
        $string = fn (string $key) => is_string($args->$key ?? null) ? trim($args->$key) : null;
        $categoryIds = is_array($args->categoryIds ?? null) ? $args->categoryIds : null;

        if ($articleId !== null && $restore !== null) {
            return ToolResult::run(fn () => $this->knowledgeBase->restore($articleId, $restore));
        }

        if ($articleId !== null) {
            if (!is_int($args->version ?? null)) {
                return ToolResult::ok(['status' => 'not_applied', 'problem' => 'no_version',
                    'explanation' => 'Pass the version KnowledgeBase returned with the article.']);
            }

            return ToolResult::run(fn () => $this->knowledgeBase->update(
                $articleId,
                $args->version,
                is_array($args->replacements ?? null) ? $args->replacements : null,
                $string('title'),
                $string('status'),
                $categoryIds,
            ));
        }

        $title = $string('title');
        $text = $string('text');

        if (!$title || !$text || !$categoryIds) {
            return ToolResult::ok(['status' => 'not_applied', 'problem' => 'incomplete',
                'explanation' => 'To change an article pass articleId and version; for a new one, title, text and categoryIds.']);
        }

        return ToolResult::run(fn () => $this->knowledgeBase->create($title, $text, $categoryIds, $string('status') ?? 'Published'));
    }
}

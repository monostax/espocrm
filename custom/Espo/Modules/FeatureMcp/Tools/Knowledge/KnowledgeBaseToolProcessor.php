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

class KnowledgeBaseToolProcessor implements ToolProcessor
{
    public function __construct(
        private KnowledgeBase $knowledgeBase,
    ) {}

    public function process(CallToolRequestParams $params, Data $data, ?RootSchema $outputSchema): CallToolResult
    {
        $args = $params->arguments ?? new stdClass();
        $articleId = is_string($args->articleId ?? null) ? $args->articleId : null;

        return ToolResult::run(fn () => match (true) {
            $articleId !== null && ($args->history ?? false) === true => $this->knowledgeBase->history($articleId),
            $articleId !== null => $this->knowledgeBase->read($articleId, (int) ($args->offset ?? 0)),
            default => $this->knowledgeBase->map(
                is_string($args->q ?? null) ? trim($args->q) : null,
                is_string($args->categoryId ?? null) ? $args->categoryId : null,
            ),
        });
    }
}

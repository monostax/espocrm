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

namespace Espo\Modules\FeatureMcp\Tools\Agents;

use Espo\Modules\FeatureMcp\Tools\Feature\ToolResult;
use Espo\Modules\Mcp\Tools\Feature\Data;
use Espo\Modules\Mcp\Tools\Mcp\Schema\General\RootSchema;
use Espo\Modules\Mcp\Tools\Mcp\Schema\Tool\CallToolRequestParams;
use Espo\Modules\Mcp\Tools\Mcp\Schema\Tool\CallToolResult;
use Espo\Modules\Mcp\Tools\Mcp\ToolsCall\ToolProcessor;
use stdClass;

class AiAgentsEditToolProcessor implements ToolProcessor
{
    public function __construct(
        private AiAgents $agents,
    ) {}

    public function process(CallToolRequestParams $params, Data $data, ?RootSchema $outputSchema): CallToolResult
    {
        $args = $params->arguments ?? new stdClass();
        $agentId = (string) ($args->agentId ?? '');

        if (is_string($args->restoreRevisionId ?? null)) {
            return ToolResult::run(fn () => $this->agents->restore($agentId, $args->restoreRevisionId));
        }

        if (!is_string($args->hash ?? null)) {
            return ToolResult::ok(['status' => 'not_applied', 'problem' => 'no_hash',
                'explanation' => 'Read the instructions with AiAgents and pass the hash it returned.']);
        }

        return ToolResult::run(fn () => $this->agents->update(
            $agentId,
            $args->hash,
            is_array($args->replacements ?? null) ? $args->replacements : null,
            is_string($args->text ?? null) ? $args->text : null,
        ));
    }
}

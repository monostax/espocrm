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

namespace Espo\Modules\FeatureMcp\Tools\Chat;

use Espo\Modules\FeatureMcp\Tools\Feature\ToolResult;
use Espo\Modules\Mcp\Tools\Feature\Data;
use Espo\Modules\Mcp\Tools\Mcp\Schema\General\RootSchema;
use Espo\Modules\Mcp\Tools\Mcp\Schema\Tool\CallToolRequestParams;
use Espo\Modules\Mcp\Tools\Mcp\Schema\Tool\CallToolResult;
use Espo\Modules\Mcp\Tools\Mcp\ToolsCall\ToolProcessor;
use stdClass;

class ConversationRepliesToolProcessor implements ToolProcessor
{
    public function __construct(
        private ChatTools $tools,
        private ConversationWriter $writer,
    ) {}

    public function process(CallToolRequestParams $params, Data $data, ?RootSchema $outputSchema): CallToolResult
    {
        $args = $params->arguments ?? new stdClass();
        $text = is_string($args->text ?? null) && trim($args->text) !== '' ? trim($args->text) : null;
        $template = is_object($args->template ?? null) ? json_decode((string) json_encode($args->template), true) : null;

        if (($text === null) === ($template === null)) {
            return ToolResult::ok(['status' => 'not_applied', 'problem' => 'text_or_template',
                'explanation' => 'Pass either text or a template.']);
        }

        return $this->tools->run($args, fn (ChatAccess $access) => $this->writer->reply(
            $access,
            (int) $args->conversationId,
            $text,
            $template,
        ));
    }
}

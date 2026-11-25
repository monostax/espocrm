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
use Throwable;
use Espo\Modules\Mcp\Tools\Feature\Data;
use Espo\Modules\Mcp\Tools\Mcp\Schema\General\RootSchema;
use Espo\Modules\Mcp\Tools\Mcp\Schema\Tool\CallToolRequestParams;
use Espo\Modules\Mcp\Tools\Mcp\Schema\Tool\CallToolResult;
use Espo\Modules\Mcp\Tools\Mcp\ToolsCall\ToolProcessor;
use stdClass;

class ConversationActionsToolProcessor implements ToolProcessor
{
    public function __construct(
        private ChatTools $tools,
        private ConversationWriter $writer,
    ) {}

    public function process(CallToolRequestParams $params, Data $data, ?RootSchema $outputSchema): CallToolResult
    {
        $args = $params->arguments ?? new stdClass();
        $snoozedUntil = null;

        if (is_string($args->snoozedUntil ?? null)) {
            try {
                $snoozedUntil = (new \DateTimeImmutable($args->snoozedUntil))->getTimestamp();
            } catch (Throwable) {
                return ToolResult::ok(['status' => 'not_applied', 'problem' => 'bad_date',
                    'explanation' => 'snoozedUntil must be a date and time such as 2026-12-01T09:00:00-03:00.']);
            }
        }

        $list = fn (string $key): ?array => is_array($args->$key ?? null) ? array_values(array_filter($args->$key, 'is_string')) : null;

        return $this->tools->run($args, fn (ChatAccess $access) => $this->writer->act($access, (int) $args->conversationId, [
            'status' => $args->status ?? null,
            'snoozedUntil' => $snoozedUntil,
            'assigneeId' => $args->assigneeId ?? null,
            'unassign' => ($args->unassign ?? false) === true,
            'teamId' => $args->teamId ?? null,
            'labels' => $list('labels'),
            'addLabels' => $list('addLabels'),
            'removeLabels' => $list('removeLabels'),
            'priority' => $args->priority ?? null,
        ]));
    }
}

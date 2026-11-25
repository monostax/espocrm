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

use Espo\Modules\Mcp\Tools\Feature\Data;
use Espo\Modules\Mcp\Tools\Mcp\Schema\General\RootSchema;
use Espo\Modules\Mcp\Tools\Mcp\Schema\Tool\CallToolRequestParams;
use Espo\Modules\Mcp\Tools\Mcp\Schema\Tool\CallToolResult;
use Espo\Modules\Mcp\Tools\Mcp\ToolsCall\ToolProcessor;
use stdClass;

class ConversationsToolProcessor implements ToolProcessor
{
    public function __construct(
        private ChatTools $tools,
        private ConversationReader $reader,
    ) {}

    public function process(CallToolRequestParams $params, Data $data, ?RootSchema $outputSchema): CallToolResult
    {
        $args = $params->arguments ?? new stdClass();
        $zone = $this->tools->timeZone();
        $int = fn (string $key): ?int => is_int($args->$key ?? null) ? $args->$key : null;
        $string = fn (string $key): ?string => is_string($args->$key ?? null) && trim($args->$key) !== '' ? trim($args->$key) : null;

        return $this->tools->run($args, function (ChatAccess $access) use ($args, $zone, $int, $string) {
            $conversationId = $int('conversationId');

            if ($string('lookup')) {
                $inboxId = $int('inboxId');

                if (!$inboxId && $conversationId && $args->lookup === 'templates') {
                    $inboxId = (int) ($this->reader->read($access, $conversationId, null, $zone)['conversation']['inbox']['id'] ?? 0);
                }

                return $this->reader->lookup($access, $args->lookup, $inboxId);
            }

            if ($conversationId && $int('messageId')) {
                return $this->reader->message($access, $conversationId, $int('messageId'), $int('offset') ?? 0, $zone);
            }

            if ($conversationId) {
                return $this->reader->read($access, $conversationId, $int('before'), $zone);
            }

            if ($string('q')) {
                return $this->reader->search($access, $string('q'), $int('page') ?? 1, $zone);
            }

            if ($string('contact')) {
                return $this->reader->byContact($access, $string('contact'));
            }

            return $this->reader->list($access, $string('status'), $string('assignee'), $int('inboxId'), $string('label'), $int('page') ?? 1);
        });
    }
}

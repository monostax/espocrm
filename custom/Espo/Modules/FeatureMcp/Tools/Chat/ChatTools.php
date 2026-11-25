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

use Espo\Core\Exceptions\Forbidden;
use Espo\Modules\FeatureMcp\Tools\Feature\ToolResult;
use Espo\Modules\FeatureMcp\Tools\Query\UserTimeZone;
use Espo\Modules\Mcp\Tools\Feature\Exceptions\NoUserAccess;
use Espo\Modules\Mcp\Tools\JsonSchema\EnumSchema;
use Espo\Modules\Mcp\Tools\JsonSchema\Schema;
use Espo\Modules\Mcp\Tools\Mcp\Schema\Tool\CallToolResult;
use stdClass;

/** What the Chat tools share: the workspace parameter, resolving the person's access and wording Chat's refusals. */
class ChatTools
{
    public function __construct(
        private ChatIdentity $identity,
        private UserTimeZone $timeZone,
    ) {}

    /** @throws NoUserAccess when the person has no Chat identity at all */
    public function workspaceSchema(): Schema
    {
        $workspaces = $this->identity->workspaces();

        if (!$workspaces) {
            throw new NoUserAccess('No Chat identity.');
        }

        return new EnumSchema(
            values: array_column($workspaces, 'accountId'),
            description: 'Workspace' . (count($workspaces) === 1 ? ' (optional, there is one)' : '') . ': ' .
                implode(', ', array_map(fn ($it) => $it['accountId'] . ' = ' . $it['workspace'], $workspaces)) . '.',
        );
    }

    public function timeZone(): ?string
    {
        return $this->timeZone->get();
    }

    /** @param callable(ChatAccess): array<string, mixed> $callback */
    public function run(stdClass $args, callable $callback): CallToolResult
    {
        try {
            $access = $this->identity->access(is_int($args->workspace ?? null) ? $args->workspace : null);

            return ToolResult::ok($callback($access));
        } catch (Forbidden $e) {
            return ToolResult::ok(['status' => 'not_permitted', 'explanation' => $e->getMessage()]);
        } catch (ChatError $e) {
            return ToolResult::ok($e->denied()
                ? ['status' => 'not_permitted', 'explanation' => 'Chat did not let you open or change that. Nothing changed.']
                : ['status' => 'not_applied', 'problem' => 'chat_refused', 'explanation' => $e->getMessage()]);
        }
    }
}

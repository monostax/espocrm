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

namespace Espo\Modules\FeatureMcp\Tools\Feature;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Conflict;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Modules\Mcp\Tools\Mcp\Schema\Tool\CallToolResult;
use stdClass;

/** Tool results for the editing tools, worded as the AI Session's (backend `code-mode/knowledge-base.ts` refusal). */
class ToolResult
{
    /** @param array<string, mixed> $content */
    public static function ok(array $content): CallToolResult
    {
        $isError = in_array($content['status'] ?? null, ['not_applied', 'not_permitted', 'changed_meanwhile', 'failed'], true);

        return new CallToolResult(
            structuredContent: json_decode((string) json_encode($content)) ?: new stdClass(),
            isError: $isError ?: null,
        );
    }

    /** @param callable(): array<string, mixed> $callback */
    public static function run(callable $callback): CallToolResult
    {
        try {
            return self::ok($callback());
        } catch (Forbidden $e) {
            return self::refused('not_permitted', 'You cannot make this change (CRM permissions); nothing changed.', $e);
        } catch (Conflict $e) {
            return self::refused('changed_meanwhile', 'Someone changed it at the same time; nothing changed. Read it again before retrying.', $e);
        } catch (NotFound $e) {
            return self::refused('not_found', 'It does not exist or you cannot open it; nothing changed.', $e);
        } catch (BadRequest $e) {
            return self::refused('rejected', 'The CRM rejected the change as invalid; nothing changed.', $e);
        }
    }

    private static function refused(string $status, string $explanation, \Throwable $e): CallToolResult
    {
        return new CallToolResult(
            structuredContent: (object) (['status' => $status, 'explanation' => $explanation] +
                ($e->getMessage() !== '' ? ['crmReason' => $e->getMessage()] : [])),
            isError: true,
        );
    }
}

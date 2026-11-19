<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureAutomation\Classes\AutomationActions;

use RuntimeException;

/**
 * Thrown by an action whose result is not ready yet (an external check still running).
 *
 * The runner parks the item until `wakeAt` (UTC, Y-m-d H:i:s). On wake the item's action
 * list runs again from the top, so the action must be re-entrant: it keeps whatever it
 * started in the item payload and, on the next pass, reads the result instead of starting
 * again. Anything the action wrote to the payload before throwing is kept.
 */
final class ActionWaiting extends RuntimeException
{
    public function __construct(public readonly string $wakeAt, string $reason = 'waiting')
    {
        parent::__construct($reason);
    }

    public static function inSeconds(int $seconds, string $reason = 'waiting'): self
    {
        return new self(gmdate('Y-m-d H:i:s', time() + max(1, $seconds)), $reason);
    }
}

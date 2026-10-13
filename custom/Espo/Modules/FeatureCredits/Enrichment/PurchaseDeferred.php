<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Enrichment;

use Espo\Core\Exceptions\Conflict;

/** Internal traversal control, not a supplier error or permission bypass. */
final class PurchaseDeferred extends Conflict
{
    public readonly string $reason;

    public static function forReason(string $reason): self
    {
        $exception = new self('Discovery purchase deferred: ' . $reason);
        $exception->reason = $reason;
        return $exception;
    }
}

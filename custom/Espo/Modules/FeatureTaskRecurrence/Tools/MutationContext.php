<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureTaskRecurrence\Tools;

/** A request-local, shared guard. Never accept this flag from API input. */
class MutationContext
{
    private int $depth = 0;

    public function coordinated(): bool { return $this->depth > 0; }

    public function run(\Closure $operation): mixed
    {
        $this->depth++;
        try { return $operation(); }
        finally { $this->depth--; }
    }
}

<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureJourney\Services;

use Espo\ORM\Entity;

/**
 * Value object passed to journey action implementations.
 */
class ActionContext
{
    /**
     * @param array<string, mixed> $params
     */
    public function __construct(
        public readonly Entity $target,
        public readonly Entity $record,
        public readonly Entity $stage,
        public readonly Entity $journey,
        public readonly string $trigger,
        public readonly array $params,
        public readonly ?string $tenantId,
    ) {}
}

<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Verification;

final readonly class EmailResult
{
    public function __construct(
        public string $status,
        public ?string $taskId = null,
        public ?string $outcome = null,
        public ?array $evidence = null,
        public ?int $tryAgainAt = null,
    ) {}
}

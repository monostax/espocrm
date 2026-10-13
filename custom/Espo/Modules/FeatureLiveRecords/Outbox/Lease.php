<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureLiveRecords\Outbox;

/** Internal worker capability. Do not log or transmit the lease token to browsers. */
final readonly class Lease
{
    public function __construct(
        public string $id,
        public string $tenantId,
        public string $token,
        public string $intent,
        public int $attempts,
        public ?string $createdAt = null,
    ) {}
}

<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureGoogleAdsConversions\Services;

/**
 * Parsed result of a single Data Manager v1 events:ingest request.
 */
final class DataManagerResult
{
    /**
     * @param list<array{field?: string, description?: string, reason?: string}> $fieldWarnings
     */
    public function __construct(
        public readonly bool $success,
        public readonly bool $retryable,
        public readonly int $httpStatus,
        public readonly ?string $requestId = null,
        public readonly array $fieldWarnings = [],
        public readonly ?string $errorCode = null,
        public readonly ?string $errorMessage = null,
    ) {}
}

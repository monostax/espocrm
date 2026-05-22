<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureMetaConversionsApi\Services;

/**
 * Immutable result of a MetaCapiClient::sendEvents call.
 */
final class SendResult
{
    public function __construct(
        public readonly bool $success,
        public readonly int $httpStatus,
        public readonly ?int $eventsReceived = null,
        public readonly mixed $requestPayload = null,
        public readonly mixed $responsePayload = null,
        public readonly ?string $errorMessage = null,
        public readonly ?string $fbtraceId = null,
    ) {}
}

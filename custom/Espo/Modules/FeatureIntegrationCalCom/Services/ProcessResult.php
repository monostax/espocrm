<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureIntegrationCalCom\Services;

/**
 * Result of {@see CalComBookingProcessor::process()}.
 *
 * Maps directly to an HTTP status the webhook controller should return.
 *
 * Status semantics:
 *   - ACCEPTED:    200 OK — payload understood, event enqueued.
 *   - SKIPPED:     200 OK — payload understood, intentionally not actioned
 *                  (avoids cal.com retry loops on configured no-ops).
 *   - BAD_REQUEST: 400 — payload malformed.
 *   - UNAUTHORIZED:401 — signature missing/invalid.
 *   - NOT_FOUND:   404 — integration id doesn't resolve to any CalComIntegration.
 */
final class ProcessResult
{
    public const ACCEPTED     = 'accepted';
    public const SKIPPED      = 'skipped';
    public const BAD_REQUEST  = 'bad_request';
    public const UNAUTHORIZED = 'unauthorized';
    public const NOT_FOUND    = 'not_found';

    /**
     * @param array<string, mixed> $details
     */
    private function __construct(
        public readonly string $status,
        public readonly int $httpStatus,
        public readonly string $message,
        public readonly array $details = [],
    ) {}

    /**
     * @param array<string, mixed> $details
     */
    public static function accepted(array $details = []): self
    {
        return new self(self::ACCEPTED, 200, 'Accepted.', $details);
    }

    public static function skipped(string $reason): self
    {
        return new self(self::SKIPPED, 200, $reason);
    }

    public static function badRequest(string $reason): self
    {
        return new self(self::BAD_REQUEST, 400, $reason);
    }

    public static function unauthorized(string $reason): self
    {
        return new self(self::UNAUTHORIZED, 401, $reason);
    }

    public static function notFound(string $reason): self
    {
        return new self(self::NOT_FOUND, 404, $reason);
    }
}

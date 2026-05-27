<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureTrackingEvent\Services;

/**
 * Result envelope returned by {@see TrackingEventIngester::ingest()}.
 *
 * Kept as a plain immutable DTO (readonly properties) so callers can pass
 * it directly to Response.writeBody after json_encode without juggling
 * mutable state.
 */
final class IngestResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly int $status,
        public readonly string $statusText,
        public readonly ?string $eventId = null,
        public readonly ?string $error = null,
    ) {}

    public static function accepted(string $eventId): self
    {
        return new self(ok: true, status: 202, statusText: 'Accepted', eventId: $eventId);
    }

    public static function badRequest(string $message): self
    {
        return new self(ok: false, status: 400, statusText: 'Bad Request', error: $message);
    }

    public static function unauthorized(string $message = 'invalid signature'): self
    {
        return new self(ok: false, status: 401, statusText: 'Unauthorized', error: $message);
    }

    public static function notFound(): self
    {
        return new self(ok: false, status: 404, statusText: 'Not Found', error: 'source not found or inactive');
    }

    public static function notImplemented(): self
    {
        return new self(ok: false, status: 501, statusText: 'Not Implemented', error: 'ingestion not yet wired up');
    }
}

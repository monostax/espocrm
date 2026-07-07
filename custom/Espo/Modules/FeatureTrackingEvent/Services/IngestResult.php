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

    /**
     * Event acknowledged but deliberately not persisted (e.g. unknown event
     * code with auto-create disabled). Returns 202 like accepted() so the
     * endpoint is not an oracle for probing valid event codes.
     */
    public static function skipped(): self
    {
        return new self(ok: true, status: 202, statusText: 'Accepted');
    }

    public static function badRequest(string $message): self
    {
        return new self(ok: false, status: 400, statusText: 'Bad Request', error: $message);
    }

    public static function unauthorized(string $message = 'invalid signature'): self
    {
        return new self(ok: false, status: 401, statusText: 'Unauthorized', error: $message);
    }

    /** Origin not on the source's allow-list (public browser path). */
    public static function forbidden(string $message = 'origin not allowed'): self
    {
        return new self(ok: false, status: 403, statusText: 'Forbidden', error: $message);
    }

    public static function notFound(): self
    {
        return new self(ok: false, status: 404, statusText: 'Not Found', error: 'source not found or inactive');
    }

    public static function tooManyRequests(): self
    {
        return new self(ok: false, status: 429, statusText: 'Too Many Requests', error: 'rate limit exceeded');
    }
}

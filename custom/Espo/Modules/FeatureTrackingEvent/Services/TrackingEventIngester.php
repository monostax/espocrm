<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureTrackingEvent\Services;

use Espo\Core\Utils\Crypt;
use Espo\Core\Utils\Log;
use Espo\Modules\FeatureTrackingEvent\Entities\TrackingSource;
use Espo\ORM\EntityManager;

/**
 * SCAFFOLD — synchronous ingestion entry point for tracking events.
 *
 * Responsibilities once wired up:
 *   1. Resolve TrackingSource by id, reject inactive / missing sources.
 *   2. Verify HMAC-SHA256 against the source's signingSecret (constant-time).
 *   3. Validate CORS origin against TrackingSource.allowedOrigins.
 *   4. Parse + normalize the JSON payload into the canonical TrackingEvent shape.
 *   5. Resolve TrackingEventType.code (auto-create if allowUnknownEventCode).
 *   6. Stitch to a Contact (by contactId, email, or anonymousId).
 *   7. Persist a TrackingEvent row + bump TrackingSource counters.
 *   8. Enqueue background jobs: AnonymousStitcher, AttributionResolver, downstream CAPI dispatch.
 *
 * Currently a no-op that returns 501 Not Implemented. The controller
 * layer + scaffolding around it is fully in place; only the body of this
 * service is intentionally left to follow-up work.
 *
 * @see \Espo\Modules\FeatureTrackingEvent\Controllers\TrackingEventReceiver
 */
class TrackingEventIngester
{
    public function __construct(
        private EntityManager $entityManager,
        private Crypt $crypt,
        private Log $log,
    ) {}

    public function ingest(
        string $sourceId,
        string $rawBody,
        ?string $signature,
        ?string $origin,
        ?string $userAgent,
        ?string $forwardedFor,
    ): IngestResult {
        $source = $this->entityManager->getEntityById(TrackingSource::ENTITY_TYPE, $sourceId);

        if (!$source instanceof TrackingSource) {
            return IngestResult::notFound();
        }

        if (!$source->get('isActive')) {
            return IngestResult::notFound();
        }

        // TODO: HMAC verification, payload validation, event-type resolution,
        //       contact stitching, persistence, counter bumps, job dispatch.
        //
        // For now we just acknowledge the source exists and short-circuit
        // with 501. Once the body is implemented, swap this for the real
        // pipeline.

        $this->log->info(
            'TrackingEventIngester: stub received event for source=' . $sourceId .
            ' bodyBytes=' . strlen($rawBody) .
            ' origin=' . ($origin ?? '-') .
            ' (ingestion not implemented yet)'
        );

        return IngestResult::notImplemented();
    }

    /**
     * Used by the preflight (OPTIONS) handler to decide whether to send
     * CORS allow headers. A null/empty origin is treated as "non-browser
     * request" and allowed by default (server-to-server has no Origin).
     */
    public function isOriginAllowed(string $sourceId, ?string $origin): bool
    {
        if ($origin === null || $origin === '') {
            return true;
        }

        $source = $this->entityManager->getEntityById(TrackingSource::ENTITY_TYPE, $sourceId);

        if (!$source instanceof TrackingSource) {
            return false;
        }

        if (!$source->get('isActive')) {
            return false;
        }

        $allowedRaw = (string) ($source->get('allowedOrigins') ?? '');

        if ($allowedRaw === '') {
            // Website sources with no allow-list are deliberately restrictive
            // for browsers (no Access-Control-Allow-Origin echoed back).
            return $source->get('kind') !== TrackingSource::KIND_WEBSITE;
        }

        $allowList = array_values(array_filter(
            array_map('trim', preg_split('/\R/', $allowedRaw) ?: []),
            fn($v) => $v !== '',
        ));

        return in_array($origin, $allowList, true);
    }
}

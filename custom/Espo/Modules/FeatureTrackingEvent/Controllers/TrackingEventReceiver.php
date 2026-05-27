<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureTrackingEvent\Controllers;

use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Utils\Log;
use Espo\Modules\FeatureTrackingEvent\Entities\TrackingSource;
use Espo\Modules\FeatureTrackingEvent\Services\TrackingEventIngester;
use Throwable;

/**
 * Public ingestion endpoint for TrackingEvent.
 *
 * Routes (both noAuth, defined in Resources/routes.json):
 *   POST    /TrackingEvent/receive/:sourceId   — accept an event payload.
 *   OPTIONS /TrackingEvent/receive/:sourceId   — CORS preflight.
 *
 * URL registered in client SDKs:
 *   https://{host}/api/v1/TrackingEvent/receive/{trackingSourceId}
 *
 * Identity:
 *   - The path param `:sourceId` IS the public TrackingSource id.
 *   - The signing secret (TrackingSource.signingSecret, encrypted at rest)
 *     is verified against the X-Tracking-Signature header if set on the
 *     source. Sources with no secret accept anonymous traffic — intended
 *     for low-trust public site beacons.
 *
 * Response strategy:
 *   - 200 / 202 on accepted events (synchronous validation, async indexing).
 *   - 400 on malformed payload (missing required keys, bad JSON).
 *   - 401 on bad HMAC.
 *   - 404 on unknown / inactive source.
 *   - Never leak the existence/absence of a specific source via timing —
 *     {@see TrackingEventIngester} handles constant-time secret comparison.
 *
 * NOTE: this is a SCAFFOLD. The {@see TrackingEventIngester} service is a
 * stub — actual HMAC verification, payload normalization, event-type lookup,
 * anonymous-id stitching and persistence still need implementing. Until
 * then, the endpoint returns 501 Not Implemented.
 */
class TrackingEventReceiver
{
    public function __construct(
        private TrackingEventIngester $ingester,
        private Log $log,
    ) {}

    /**
     * POST /TrackingEvent/receive/:sourceId
     */
    public function postActionReceive(Request $request, Response $response): void
    {
        $sourceId = $request->getRouteParam('sourceId');

        if (!$sourceId || !is_string($sourceId)) {
            $response->setStatus(400, 'Bad Request');
            $response->writeBody(json_encode(['ok' => false, 'error' => 'sourceId missing']) ?: '');

            return;
        }

        try {
            $result = $this->ingester->ingest(
                sourceId: $sourceId,
                rawBody: $request->getBodyContents() ?? '',
                signature: $request->getHeader('X-Tracking-Signature'),
                origin: $request->getHeader('Origin'),
                userAgent: $request->getHeader('User-Agent'),
                forwardedFor: $request->getHeader('X-Forwarded-For'),
            );
        } catch (Throwable $e) {
            $this->log->error(
                'TrackingEventReceiver: unexpected error ingesting source=' . $sourceId .
                ' — ' . $e->getMessage()
            );

            $response->setStatus(500, 'Internal Server Error');
            $response->writeBody(json_encode(['ok' => false, 'error' => 'internal']) ?: '');

            return;
        }

        $this->applyCorsHeaders($response, $request->getHeader('Origin'));

        $response->setStatus($result->status, $result->statusText);
        $response->setHeader('Content-Type', 'application/json');
        $response->writeBody(json_encode([
            'ok'      => $result->ok,
            'eventId' => $result->eventId,
            'error'   => $result->error,
        ]) ?: '{}');
    }

    /**
     * OPTIONS /TrackingEvent/receive/:sourceId — CORS preflight.
     *
     * Always returns 204 with CORS headers derived from the source's
     * allowedOrigins. Treats unknown sources as 404 so that browsers
     * stop probing dead endpoints, but does not leak per-secret info.
     */
    public function optionsActionPreflight(Request $request, Response $response): void
    {
        $sourceId = $request->getRouteParam('sourceId');

        if (!$sourceId || !is_string($sourceId)) {
            $response->setStatus(400, 'Bad Request');

            return;
        }

        $origin = $request->getHeader('Origin');

        if (!$this->ingester->isOriginAllowed($sourceId, $origin)) {
            $response->setStatus(403, 'Forbidden');

            return;
        }

        $this->applyCorsHeaders($response, $origin);
        $response->setHeader('Access-Control-Allow-Methods', 'POST, OPTIONS');
        $response->setHeader('Access-Control-Allow-Headers', 'Content-Type, X-Tracking-Signature');
        $response->setHeader('Access-Control-Max-Age', '600');
        $response->setStatus(204, 'No Content');
    }

    private function applyCorsHeaders(Response $response, ?string $origin): void
    {
        if ($origin === null || $origin === '') {
            return;
        }

        // The ingester has already validated that the origin is on the
        // allow-list; echo it back literally so credentialed requests
        // (cookies, custom headers) work. Never `*` when echoing origin.
        $response->setHeader('Access-Control-Allow-Origin', $origin);
        $response->setHeader('Vary', 'Origin');
    }
}

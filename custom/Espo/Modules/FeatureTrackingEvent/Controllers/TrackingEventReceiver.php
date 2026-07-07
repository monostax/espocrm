<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureTrackingEvent\Controllers;

use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Utils\Log;
use Espo\Modules\FeatureTrackingEvent\Services\TrackingEventIngester;
use Throwable;

/**
 * Public ingestion endpoint for TrackingEvent.
 *
 * Routes (both noAuth, defined in Resources/routes.json):
 *   POST    /TrackingEvent/receive/:sourceId   — accept an event payload.
 *   OPTIONS /TrackingEvent/receive/:sourceId   — CORS preflight (NOTE: in
 *     the containerized deployment Apache answers all OPTIONS requests
 *     before PHP; this handler only runs where that rewrite is absent.
 *     The browser SDK therefore uses CORS "simple requests" only —
 *     Content-Type: text/plain, no custom headers — which need no
 *     preflight at all).
 *
 * URL registered in client SDKs:
 *   https://{host}/api/v1/TrackingEvent/receive/{trackingSourceId}
 *
 * Trust model (see {@see TrackingEventIngester} for the full pipeline):
 *   - Trusted sources (kind=Server/Other) MUST sign the raw body
 *     with HMAC-SHA256 in the X-Tracking-Signature header.
 *   - Public sources (kind=Website/Mobile) are unsigned; they are gated by
 *     Origin allow-list (Website), rate limiting and privileged-field
 *     stripping instead.
 *
 * Response strategy:
 *   - 202 on accepted events; also 202 for deliberately skipped ones
 *     (unknown code with auto-create off) so the endpoint is not an oracle.
 *   - 400 on malformed payload, 401 on bad HMAC, 403 on disallowed Origin,
 *     404 on unknown/inactive source, 429 (+ Retry-After) on rate limit.
 *   - CORS headers are echoed only when the origin passed the allow-list
 *     (never on 403).
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

        // Never echo CORS allow headers back to an origin that failed the
        // allow-list — a 403 response must not be readable cross-origin.
        if ($result->status !== 403) {
            $this->applyCorsHeaders($response, $request->getHeader('Origin'));
        }

        if ($result->status === 429) {
            $response->setHeader('Retry-After', '60');
        }

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

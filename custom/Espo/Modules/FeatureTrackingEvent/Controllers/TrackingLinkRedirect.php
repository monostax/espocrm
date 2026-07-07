<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureTrackingEvent\Controllers;

use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Utils\Log;
use Espo\Modules\FeatureTrackingEvent\Services\TrackingLinkRedirector;
use Throwable;

/**
 * Public redirect endpoint for TrackingLink (noAuth, Resources/routes.json):
 *
 *   GET /TrackingLink/go/:slug
 *
 * Production fronting: a dedicated short domain (config trackingLinkDomain)
 * routes GET {domain}/{slug} to this path at the edge — and nothing else on
 * that host. Keeping the CRM's own origin out of public links means a
 * phishing/blocklist incident on the link domain can never taint the login
 * origin, and no CRM session cookies ride along with redirect GETs.
 *
 * Response contract:
 *   302 + Location            — active slug (recording is best-effort and
 *                               NEVER blocks the redirect).
 *   404                       — unknown or inactive slug. Inactive = dead
 *                               campaign: the link stops working, not just
 *                               stops counting.
 *   Cache-Control: no-store   — a cached redirect would swallow clicks.
 */
class TrackingLinkRedirect
{
    public function __construct(
        private TrackingLinkRedirector $redirector,
        private Log $log,
    ) {}

    public function getActionGo(Request $request, Response $response): void
    {
        $slug = $request->getRouteParam('slug');

        $response->setHeader('Cache-Control', 'no-store, private');

        if (!is_string($slug) || $slug === '') {
            $this->notFound($response);

            return;
        }

        try {
            $url = $this->redirector->resolve(
                slug: $slug,
                query: $request->getQueryParams(),
                referrer: $request->getHeader('Referer'),
                userAgent: $request->getHeader('User-Agent'),
                forwardedFor: $request->getHeader('X-Forwarded-For'),
            );
        } catch (Throwable $e) {
            $this->log->error("TrackingLinkRedirect: unexpected error for slug={$slug} — " . $e->getMessage());

            $this->notFound($response);

            return;
        }

        if ($url === null) {
            $this->notFound($response);

            return;
        }

        $response->setStatus(302, 'Found');
        $response->setHeader('Location', $url);
        $response->writeBody('');
    }

    private function notFound(Response $response): void
    {
        $response->setStatus(404, 'Not Found');
        $response->setHeader('Content-Type', 'text/plain');
        $response->writeBody('Not found.');
    }
}

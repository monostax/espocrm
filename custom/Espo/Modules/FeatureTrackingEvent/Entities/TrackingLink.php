<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureTrackingEvent\Entities;

use Espo\Core\ORM\Entity;

/**
 * TrackingLink — a trackable short link (Mautic/bit.ly model).
 *
 * Each row owns an immutable random `slug`; the public redirect endpoint
 *   GET /api/v1/TrackingLink/go/{slug}
 * (fronted by a dedicated short domain at the edge, see README) records a
 * TrackingEvent (code = eventCode, default `link_clicked`) and 302-redirects
 * to `targetUrl`.
 *
 * Identity model:
 *   - Anonymous clicks: the redirect mints a fresh anonymousId, stamps it on
 *     the click event and appends `mstx_a` to the target URL so tracker.js
 *     on the landing page adopts it — the click merges into the visitor's
 *     journey and is stitched retroactively when they identify.
 *   - Known-recipient clicks: a per-recipient `?c={token}` (HMAC, see
 *     Services\ContactToken) resolves the Contact at redirect time and is
 *     passed through as `mstx_c` so tracker.js identifies the landing
 *     session too.
 *
 * Ad platform params (fbclid/gclid/utm_* — anything on the short URL) are
 * forwarded to the target URL untouched and captured server-side into the
 * click event's attribution, so short links never break ad attribution.
 *
 * Tenancy: teams are user-assigned (or cascaded from the tracking source);
 * tenant derives via AssignTenantFromTeam. The linked TrackingSource must
 * belong to the same tenant and must not be the internal kind=CRM channel
 * (ValidateLink hook).
 */
class TrackingLink extends Entity
{
    public const ENTITY_TYPE = 'TrackingLink';

    public const EVENT_CODE_DEFAULT = 'link_clicked';

    /** Lowercase-only alphabet: MySQL/MariaDB default collations are
     * case-insensitive, so mixed-case slugs could collide. */
    public const SLUG_ALPHABET = 'abcdefghijklmnopqrstuvwxyz0123456789';

    public const SLUG_LENGTH = 10;

    /**
     * WhatsApp click-to-chat hosts (wa.me and the whatsapp.com family).
     * Links targeting these get the zero-width identity embed instead of
     * the mstx_* query handoff — see TrackingLinkRedirector.
     */
    public static function isWhatsAppHost(string $host): bool
    {
        $host = strtolower($host);

        return $host === 'wa.me' || $host === 'www.wa.me'
            || $host === 'whatsapp.com' || str_ends_with($host, '.whatsapp.com');
    }

    /**
     * Destination number of a WhatsApp click-to-chat URL, digits only
     * (E.164 without the +). wa.me carries it as the first path segment;
     * api./web.whatsapp.com/send carry it as the `phone` query param.
     * Null for non-WhatsApp or phoneless URLs.
     */
    public static function whatsAppPhoneFromUrl(string $url): ?string
    {
        $parts = parse_url($url);

        if ($parts === false || !isset($parts['host']) || !self::isWhatsAppHost($parts['host'])) {
            return null;
        }

        $candidate = null;

        if (isset($parts['query']) && $parts['query'] !== '') {
            parse_str($parts['query'], $query);

            if (isset($query['phone']) && is_string($query['phone'])) {
                $candidate = $query['phone'];
            }
        }

        if ($candidate === null && isset($parts['path'])) {
            $segment = explode('/', trim($parts['path'], '/'))[0] ?? '';

            if ($segment !== '' && strtolower($segment) !== 'send') {
                $candidate = $segment;
            }
        }

        if ($candidate === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $candidate) ?? '';

        return strlen($digits) >= 8 && strlen($digits) <= 15 ? $digits : null;
    }
}

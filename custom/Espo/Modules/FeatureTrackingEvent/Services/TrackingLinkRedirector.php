<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureTrackingEvent\Services;

use DateTimeImmutable;
use DateTimeZone;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Log;
use Espo\Modules\FeatureTrackingEvent\Entities\TrackingEvent;
use Espo\Modules\FeatureTrackingEvent\Entities\TrackingEventType;
use Espo\Modules\FeatureTrackingEvent\Entities\TrackingLink;
use Espo\Modules\FeatureTrackingEvent\Entities\TrackingSource;
use Espo\ORM\EntityManager;
use Espo\ORM\Query\Part\Condition;
use Espo\ORM\Query\Part\Expression;
use Throwable;

/**
 * Resolves a TrackingLink slug into a redirect URL, recording the click as
 * a TrackingEvent on the way through.
 *
 * Design invariants:
 *
 *   REDIRECT > RECORD. Once an active link is resolved, the visitor gets
 *   their 302 no matter what — recording failures, rate limits, missing
 *   source config are logged and swallowed. A tracking pipeline must never
 *   turn a marketing link into an error page.
 *
 *   PARAM PASS-THROUGH. Ad platforms append click ids (fbclid/gclid/...) to
 *   whatever URL they are given — including short URLs. Every incoming query
 *   param except our own `c` token is forwarded onto the target URL so
 *   landing-page pixels and tracker.js still see them; the same params are
 *   also captured server-side into the click event's attribution (works even
 *   when landing-page JS is ad-blocked — the redirect is server-observed).
 *
 *   IDENTITY. `?c={ContactToken}` resolves the Contact at redirect time
 *   (must verify AND belong to the link's tenant, else degrades to
 *   anonymous). A fresh anonymousId is minted either way and handed to the
 *   landing page as `mstx_a` so tracker.js can adopt it — anonymous clicks
 *   join the visitor's future journey and are stitched when they identify.
 *   The token is passed through as `mstx_c` so tracker.js identifies the
 *   landing session too. `mstx_l={slug}` ties landing attribution to the
 *   link.
 *
 *   BOTS. Link scanners and chat-app unfurl bots (WhatsApp/Slack/Telegram
 *   previews) fire GETs. Hits are recorded with payload.isLikelyBot=true
 *   rather than dropped — the ledger keeps raw truth; analytics dedupe.
 *
 *   WHATSAPP TARGETS. When targetUrl is a wa.me / *.whatsapp.com
 *   click-to-chat URL there is no landing page to run tracker.js, so the
 *   mstx_* query handoff is useless. Instead the minted anonymousId is
 *   embedded into the pre-filled `text` param as invisible zero-width
 *   characters (ZeroWidthCodec — the tintim.app technique); when the lead
 *   sends the message unmodified, the Chatwoot sync pipeline extracts it
 *   (WhatsAppAttributionLinker) and joins the conversation to this click.
 *   The click event carries payload.isWhatsApp + payload.waPhone (the
 *   destination number) so tokenless conversations can still be matched
 *   by time proximity.
 *
 * Trust level: clicks are unauthenticated public traffic — channel=browser,
 * same per-IP rate cap as the public ingest path plus a per-link fixed cap.
 */
class TrackingLinkRedirector
{
    /** Per-link fixed-window click-recording budget (redirects are never limited). */
    private const LINK_LIMIT_PER_MINUTE = 600;

    /** Query params consumed by this endpoint (never forwarded as-is). */
    private const RESERVED_PARAMS = ['c'];

    private const BOT_UA_PATTERN =
        '/bot|crawl|spider|slurp|preview|scan|monitor|curl|wget|python-requests|headless|' .
        'facebookexternalhit|whatsapp|telegrambot|slackbot|twitterbot|linkedinbot|discordbot|skypeuripreview/i';

    public function __construct(
        private EntityManager $entityManager,
        private Log $log,
        private Config $config,
        private TrackingEventPersister $persister,
        private RateLimiter $rateLimiter,
        private ContactToken $contactToken,
    ) {}

    /**
     * Resolve the slug and record the click. Returns the final redirect URL,
     * or null when the slug is unknown/inactive (caller answers 404).
     *
     * @param array<string, mixed> $query Raw query params from the request.
     */
    public function resolve(
        string $slug,
        array $query,
        ?string $referrer,
        ?string $userAgent,
        ?string $forwardedFor,
    ): ?string {
        if ($slug === '' || strlen($slug) > 16) {
            return null;
        }

        $link = $this->entityManager
            ->getRDBRepository(TrackingLink::ENTITY_TYPE)
            ->where(['slug' => $slug, 'deleted' => false])
            ->findOne();

        if (!$link instanceof TrackingLink || !$link->get('isActive')) {
            return null;
        }

        $targetUrl = $link->get('targetUrl');

        if (!is_string($targetUrl) || $targetUrl === '') {
            return null;
        }

        $anonymousId = 'lnk_' . bin2hex(random_bytes(14)); // 32 chars, fits SDK format

        $token = isset($query['c']) && is_string($query['c']) ? $query['c'] : null;

        $finalUrl = $this->buildFinalUrl($targetUrl, $query, $slug, $anonymousId, $token);

        try {
            $this->recordClick($link, $query, $finalUrl, $anonymousId, $token, $referrer, $userAgent, $forwardedFor);
        } catch (Throwable $e) {
            $this->log->error(
                "TrackingLinkRedirector: failed to record click for slug={$slug}: " . $e->getMessage()
            );
        }

        return $finalUrl;
    }

    /**
     * Mint a shareable per-recipient URL for a known Contact. Used by the
     * authenticated mint endpoint (Controllers\TrackingLinkMint).
     */
    public function buildContactUrl(TrackingLink $link, string $contactId, ?int $ttlDays = null): string
    {
        $tenantId = (string) ($link->get('tenantId') ?? '');

        $token = $this->contactToken->mint($contactId, $tenantId, $ttlDays);

        return $this->publicUrl($link) . '?c=' . urlencode($token);
    }

    /**
     * Public short URL for a link: the dedicated short domain when
     * configured, else the API path on siteUrl.
     */
    public function publicUrl(TrackingLink $link): string
    {
        $slug = (string) $link->get('slug');

        $domain = $this->configString('trackingLinkDomain');

        if ($domain !== '') {
            return rtrim($domain, '/') . '/' . $slug;
        }

        return rtrim($this->configString('siteUrl'), '/') . '/api/v1/TrackingLink/go/' . $slug;
    }

    /**
     * @param array<string, mixed> $query
     */
    private function recordClick(
        TrackingLink $link,
        array $query,
        string $finalUrl,
        string $anonymousId,
        ?string $token,
        ?string $referrer,
        ?string $userAgent,
        ?string $forwardedFor,
    ): void {
        $clientIp = $this->resolveClientIp($forwardedFor);

        if (!$this->rateLimiter->allowSource('link-' . $link->getId(), self::LINK_LIMIT_PER_MINUTE)) {
            return;
        }

        if (!$this->rateLimiter->allowIp($clientIp)) {
            return;
        }

        $source = $this->entityManager->getEntityById(
            TrackingSource::ENTITY_TYPE,
            (string) ($link->get('trackingSourceId') ?? ''),
        );

        if (!$source instanceof TrackingSource || !$source->get('isActive') || $source->isInternalKind()) {
            // Link redirects regardless; recording needs a usable source.
            return;
        }

        $tenantId = $source->get('tenantId');

        if (!is_string($tenantId) || $tenantId === '') {
            $this->log->warning("TrackingLinkRedirector: source={$source->getId()} has no tenant; click not recorded.");

            return;
        }

        $code = $this->persister->normalizeCode($link->get('eventCode'))
            ?? TrackingLink::EVENT_CODE_DEFAULT;

        $type = $this->persister->resolveEventType($source, $code, $tenantId);

        if ($type === null || $type->get('isActive') === false) {
            return; // tenant muted the code / locked the dictionary — opt-out
        }

        $teamsIds = $this->persister->resolveTeamsIds($type, $source, $tenantId);

        if ($teamsIds === []) {
            $this->log->warning("TrackingLinkRedirector: no teams resolve for link={$link->getId()}; click not recorded.");

            return;
        }

        $contactId = $this->resolveContactId($token, $tenantId);

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $nowString = $now->format('Y-m-d H:i:s');

        $payload = [
            'slug' => $link->get('slug'),
            'linkName' => $link->get('name'),
        ];

        $waPhone = $this->whatsAppPhone((string) $link->get('targetUrl'));

        if ($waPhone !== null) {
            $payload['isWhatsApp'] = true;
            $payload['waPhone'] = $waPhone;
        }

        $isLikelyBot = is_string($userAgent) && preg_match(self::BOT_UA_PATTERN, $userAgent) === 1;

        if ($isLikelyBot) {
            $payload['isLikelyBot'] = true;
        }

        $attributes = [
            'name' => $code . ' @ ' . $nowString,
            'code' => $code,
            'occurredAt' => $nowString,
            'receivedAt' => $nowString,
            'status' => TrackingEvent::STATUS_RECEIVED,
            'channel' => TrackingEvent::CHANNEL_BROWSER,
            'trackingSourceId' => $source->getId(),
            'trackingEventTypeId' => $type->getId(),
            'trackingLinkId' => $link->getId(),
            'url' => mb_substr($finalUrl, 0, 1024),
            'referrer' => is_string($referrer) && $referrer !== '' ? mb_substr($referrer, 0, 1024) : null,
            'userAgent' => is_string($userAgent) && $userAgent !== '' ? mb_substr($userAgent, 0, 512) : null,
            'ipAddress' => $clientIp !== null ? mb_substr($clientIp, 0, 64) : null,
            'anonymousId' => $anonymousId,
            'contactId' => $contactId,
            'payload' => (object) $payload,
            'attribution' => $this->buildAttribution($query, $finalUrl, $referrer),
            'teamsIds' => $teamsIds,
            'tenantId' => $tenantId,
        ];

        $this->persister->persistEvent($attributes);

        $this->persister->bumpSourceCounters($source, $now);
        $this->persister->bumpTypeCounters($type, $now);
        $this->bumpLinkCounters($link, $now);
    }

    /**
     * Token → Contact, requiring tenant agreement with the link's source and
     * an existing (non-deleted) Contact row. Any failure degrades silently
     * to anonymous — a stale email link must still redirect and record.
     */
    private function resolveContactId(?string $token, string $tenantId): ?string
    {
        if ($token === null || $token === '') {
            return null;
        }

        $data = $this->contactToken->verify($token);

        if ($data === null || $data['tenantId'] !== $tenantId) {
            return null;
        }

        $contact = $this->entityManager
            ->getRDBRepository('Contact')
            ->where(['id' => $data['contactId'], 'tenantId' => $tenantId, 'deleted' => false])
            ->findOne();

        return $contact?->getId();
    }

    /**
     * Target URL + forwarded params + our mstx_* handoff params. Precedence
     * on collision: target's own params < incoming click params < mstx_*.
     *
     * @param array<string, mixed> $query
     */
    private function buildFinalUrl(
        string $targetUrl,
        array $query,
        string $slug,
        string $anonymousId,
        ?string $token,
    ): string {
        $parts = parse_url($targetUrl);

        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return $targetUrl; // malformed legacy row: redirect verbatim
        }

        $params = [];

        if (isset($parts['query']) && $parts['query'] !== '') {
            parse_str($parts['query'], $params);
        }

        foreach ($query as $key => $value) {
            if (!is_string($key) || in_array($key, self::RESERVED_PARAMS, true)) {
                continue;
            }

            if (is_string($value) && strlen($value) <= 2048) {
                $params[$key] = $value;
            }
        }

        if ($this->isWhatsAppHost($parts['host'])) {
            // Click-to-chat target: no landing page will run tracker.js, so
            // query handoff params are dead weight. The anonymousId travels
            // inside the pre-filled message instead, as invisible zero-width
            // characters extracted later from the inbound WhatsApp message.
            // A visible `text` is required (an all-invisible message would
            // look empty and never be sent) — without one we skip embedding
            // and rely on the time-window fallback match.
            if (isset($params['text']) && is_string($params['text']) && $params['text'] !== '') {
                $params['text'] = ZeroWidthCodec::embed($params['text'], $anonymousId);
            }
        } else {
            $params['mstx_l'] = $slug;
            $params['mstx_a'] = $anonymousId;

            if ($token !== null && $token !== '' && strlen($token) <= 512) {
                $params['mstx_c'] = $token;
            }
        }

        $url = $parts['scheme'] . '://' . $parts['host'];

        if (isset($parts['port'])) {
            $url .= ':' . $parts['port'];
        }

        $url .= $parts['path'] ?? '/';
        $url .= '?' . http_build_query($params);

        if (isset($parts['fragment']) && $parts['fragment'] !== '') {
            $url .= '#' . $parts['fragment'];
        }

        return $url;
    }

    /**
     * Server-side attribution snapshot from the click URL — the ad-block-proof
     * copy of what tracker.js would capture on the landing page.
     *
     * @param array<string, mixed> $query
     */
    private function buildAttribution(array $query, string $finalUrl, ?string $referrer): ?object
    {
        $found = [];

        foreach (TrackingEventPersister::ATTRIBUTION_PARAMS as $name) {
            $value = $query[$name] ?? null;

            if (is_string($value) && $value !== '') {
                $found[$name] = mb_substr($value, 0, 512);
            }
        }

        if ($found === []) {
            return null;
        }

        $found['landing_page'] = mb_substr($finalUrl, 0, 1024);

        if (is_string($referrer) && $referrer !== '') {
            $found['referrer'] = mb_substr($referrer, 0, 1024);
        }

        $found['captured_at'] = (int) floor(microtime(true) * 1000);

        return (object) $found;
    }

    private function isWhatsAppHost(string $host): bool
    {
        return TrackingLink::isWhatsAppHost($host);
    }

    private function whatsAppPhone(string $targetUrl): ?string
    {
        return TrackingLink::whatsAppPhoneFromUrl($targetUrl);
    }

    private function bumpLinkCounters(TrackingLink $link, DateTimeImmutable $now): void
    {
        try {
            $query = $this->entityManager
                ->getQueryBuilder()
                ->update()
                ->in(TrackingLink::ENTITY_TYPE)
                ->set([
                    'totalClicks' => Expression::add(Expression::column('totalClicks'), 1),
                    'lastClickAt' => $now->format('Y-m-d H:i:s'),
                ])
                ->where(Condition::equal(Expression::column('id'), $link->getId()))
                ->build();

            $this->entityManager->getQueryExecutor()->execute($query);
        } catch (Throwable $e) {
            $this->log->warning('TrackingLinkRedirector: link counter bump failed — ' . $e->getMessage());
        }
    }

    /**
     * Rightmost X-Forwarded-For hop — same policy as TrackingEventIngester.
     */
    private function resolveClientIp(?string $forwardedFor): ?string
    {
        if ($forwardedFor === null || trim($forwardedFor) === '') {
            return null;
        }

        $parts = array_values(array_filter(array_map('trim', explode(',', $forwardedFor)), fn($v) => $v !== ''));

        return $parts === [] ? null : end($parts);
    }

    private function configString(string $param): string
    {
        $value = $this->config->get($param);

        return is_string($value) ? trim($value) : '';
    }
}

<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureTrackingEvent\Services;

use DateTimeImmutable;
use DateTimeZone;
use Espo\Core\Job\JobSchedulerFactory;
use Espo\Core\Utils\Crypt;
use Espo\Core\Utils\Log;
use Espo\Modules\FeatureTrackingEvent\Entities\TrackingEvent;
use Espo\Modules\FeatureTrackingEvent\Entities\TrackingSource;
use Espo\Modules\FeatureTrackingEvent\Jobs\AnonymousStitcher;
use Espo\ORM\EntityManager;
use Throwable;

/**
 * Synchronous ingestion pipeline for tracking events. Dual trust model:
 *
 *   TRUSTED path — kind=Server/Other (server-to-server):
 *     - X-Tracking-Signature (hex HMAC-SHA256 of the raw body, optional
 *       "sha256=" prefix) is MANDATORY and verified in constant time.
 *     - Payload may claim contactId, ipAddress, userAgent, occurredAt and
 *       a parent link — the caller is authenticated infrastructure.
 *
 *   PUBLIC path — kind=Website/Mobile (browsers/apps; no secret possible):
 *     - No signature. The sourceId in the URL is a public write key
 *       (Mixpanel model).
 *     - kind=Website: the Origin header must match the source's allow-list
 *       (checked here on POST, not only on preflight).
 *     - Privileged payload fields (contactId, ipAddress, userAgent,
 *       parentType/parentId) are STRIPPED; ip/UA are derived server-side.
 *     - Per-IP rate limiting on top of the per-source limit.
 *     - email identity claims are verified against
 *       identityVerificationSecret when configured (body-borne signature —
 *       headers would force a CORS preflight and break sendBeacon).
 *
 * Body is always parsed from the raw string: browsers send
 * Content-Type: text/plain so the POST stays a CORS "simple request"
 * (no preflight; sendBeacon-compatible), and Espo's getParsedBody()
 * rejects text/plain anyway.
 *
 * Persistence concerns (event-type resolution/auto-create, the teams
 * fallback chain, silent saves, atomic counter bumps, source status
 * bookkeeping) are delegated to TrackingEventPersister, which is shared
 * with InternalEventRecorder so the external and in-process write paths
 * cannot drift.
 *
 * Canonical payload shape (all optional unless noted):
 * {
 *   "code": "purchase",                  // REQUIRED  [a-z][a-z0-9_]{0,63}
 *   "occurredAt": "2026-07-07T12:00:00Z",
 *   "anonymousId": "anon-abc",
 *   "contactId": "...",                  // trusted only
 *   "contactToken": "b64url.b64url",     // CRM-minted ContactToken (both paths)
 *   "email": "user@example.com",         // identity claim
 *   "identitySignature": "hex",          // HMAC(email) or HMAC(email:ts)
 *   "identityTimestamp": 1780000000,     // unix seconds; 24h replay window
 *   "url": "...", "referrer": "...",
 *   "userAgent": "...", "ipAddress": "...", // trusted only
 *   "value": 199.9, "currency": "BRL",
 *   "attribution": { "utm_source": "...", "gclid": "..." },
 *   "properties": { ... },               // free-form extras
 *   "parentType": "Opportunity", "parentId": "..." // trusted only
 * }
 *
 * @see \Espo\Modules\FeatureTrackingEvent\Controllers\TrackingEventReceiver
 */
class TrackingEventIngester
{
    /** noAuth endpoint — enforce our own cap (no LimitRequestBody in the vhost). */
    private const MAX_BODY_BYTES = 65536;

    /** Replay window for timestamped identity signatures (seconds). */
    private const IDENTITY_SIGNATURE_MAX_AGE = 86400;

    /** Payload keys only the trusted path may assert. */
    private const PRIVILEGED_KEYS = ['contactId', 'ipAddress', 'userAgent', 'parentType', 'parentId'];

    public function __construct(
        private EntityManager $entityManager,
        private Crypt $crypt,
        private Log $log,
        private JobSchedulerFactory $jobSchedulerFactory,
        private RateLimiter $rateLimiter,
        private TrackingEventPersister $persister,
        private ContactToken $contactToken,
        private TrackingEventNameBuilder $nameBuilder,
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

        if (!$source instanceof TrackingSource || !$source->get('isActive')) {
            return IngestResult::notFound();
        }

        if ($source->isInternalKind()) {
            // kind=CRM is the in-process channel (InternalEventRecorder).
            // It must never accept events over HTTP — pretend it does not
            // exist.
            return IngestResult::notFound();
        }

        if (strlen($rawBody) > self::MAX_BODY_BYTES) {
            return $this->reject($source, TrackingSource::STATUS_BAD_REQUEST, 'payload too large');
        }

        if (!$this->rateLimiter->allowSource($sourceId, (int) ($source->get('rateLimitPerMinute') ?? 0))) {
            return IngestResult::tooManyRequests();
        }

        $trusted = $source->isTrustedKind();
        $clientIp = $this->resolveClientIp($forwardedFor);

        if (!$trusted && !$this->rateLimiter->allowIp($clientIp)) {
            return IngestResult::tooManyRequests();
        }

        if ($trusted) {
            $error = $this->verifySignature($source, $rawBody, $signature);

            if ($error !== null) {
                return $this->reject($source, TrackingSource::STATUS_UNAUTHORIZED, $error, IngestResult::unauthorized($error));
            }
        } elseif ($source->get('kind') === TrackingSource::KIND_WEBSITE) {
            // Browser path: Origin is mandatory and must be allow-listed.
            // Checked on the actual POST — the OPTIONS preflight never
            // reaches PHP in this deployment (Apache short-circuits it).
            if ($origin === null || $origin === '' || !$this->originAllowed($source, $origin)) {
                return IngestResult::forbidden();
            }
        }

        $data = json_decode($rawBody, true);

        if (!is_array($data)) {
            return $this->reject($source, TrackingSource::STATUS_BAD_REQUEST, 'body is not a JSON object');
        }

        if (!$trusted) {
            foreach (self::PRIVILEGED_KEYS as $key) {
                unset($data[$key]);
            }
        }

        $code = $this->persister->normalizeCode($data['code'] ?? null);

        if ($code === null) {
            return $this->reject(
                $source,
                TrackingSource::STATUS_BAD_REQUEST,
                'code is required (lowercase letters, digits, underscores; max 64 chars)',
            );
        }

        $tenantId = $source->get('tenantId');

        if (!is_string($tenantId) || $tenantId === '') {
            $this->log->error("TrackingEventIngester: source={$sourceId} has no tenant; refusing ingest (assign teams on the source).");

            return $this->reject($source, TrackingSource::STATUS_BAD_REQUEST, 'source is not fully configured');
        }

        $type = $this->persister->resolveEventType($source, $code, $tenantId);

        if ($type === null) {
            return $this->reject(
                $source,
                TrackingSource::STATUS_SKIPPED,
                "unknown event code '{$code}' (auto-create disabled)",
                IngestResult::skipped(),
            );
        }

        if ($type->get('isActive') === false) {
            return $this->reject($source, TrackingSource::STATUS_SKIPPED, "event code '{$code}' is inactive", IngestResult::skipped());
        }

        $teamsIds = $this->persister->resolveTeamsIds($type, $source, $tenantId);

        if ($teamsIds === []) {
            $this->log->error("TrackingEventIngester: source={$sourceId} type={$code} resolve no teams; refusing ingest.");

            return $this->reject($source, TrackingSource::STATUS_BAD_REQUEST, 'source is not fully configured');
        }

        $anonymousId = $this->str($data['anonymousId'] ?? null, 64);
        $contact = $this->resolveContact($source, $data, $trusted, $tenantId);

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $occurredAt = $this->resolveOccurredAt($data['occurredAt'] ?? null, $now);

        $url = $this->str($data['url'] ?? null, 1024);

        // Context fragment: page/form title if the SDK sent one, else the
        // URL path ('/pricing').
        $nameDetail = $this->str($data['title'] ?? null, 120)
            ?? $this->urlPath($url);

        $attributes = [
            'name' => $this->nameBuilder->build($code, $tenantId, $type->get('name'), $nameDetail),
            'code' => $code,
            'occurredAt' => $occurredAt,
            'receivedAt' => $now->format('Y-m-d H:i:s'),
            'status' => TrackingEvent::STATUS_RECEIVED,
            'channel' => $trusted ? TrackingEvent::CHANNEL_SERVER : TrackingEvent::CHANNEL_BROWSER,
            'trackingSourceId' => $source->getId(),
            'trackingEventTypeId' => $type->getId(),
            'url' => $url,
            'referrer' => $this->str($data['referrer'] ?? null, 1024),
            'userAgent' => $this->str(($trusted ? ($data['userAgent'] ?? null) : null) ?? $userAgent, 512),
            'ipAddress' => $this->str(($trusted ? ($data['ipAddress'] ?? null) : null) ?? $clientIp, 64),
            'anonymousId' => $anonymousId,
            'contactId' => $contact?->getId(),
            'payload' => (object) $data,
            'attribution' => is_array($data['attribution'] ?? null) ? (object) $data['attribution'] : null,
            'value' => is_numeric($data['value'] ?? null) ? (float) $data['value'] : null,
            'currency' => in_array($data['currency'] ?? null, TrackingEventPersister::CURRENCIES, true) ? $data['currency'] : '',
            'teamsIds' => $teamsIds,
            'tenantId' => $tenantId,
        ];

        if ($trusted) {
            $this->applyParent($attributes, $data);
        }

        try {
            $event = $this->persister->persistEvent($attributes);
        } catch (Throwable $e) {
            $this->log->error("TrackingEventIngester: failed to persist event for source={$sourceId}: " . $e->getMessage());

            return $this->reject($source, TrackingSource::STATUS_BAD_REQUEST, 'persistence failure');
        }

        $this->persister->bumpSourceCounters($source, $now);
        $this->persister->bumpTypeCounters($type, $now);

        if ($contact !== null && $anonymousId !== null) {
            $this->jobSchedulerFactory
                ->create()
                ->setClassName(AnonymousStitcher::class)
                ->setData([
                    'contactId' => $contact->getId(),
                    'anonymousId' => $anonymousId,
                    'tenantId' => $tenantId,
                ])
                ->setGroup('tracking-stitch-' . substr(md5($anonymousId), 0, 16))
                ->schedule();
        }

        return IngestResult::accepted($event->getId());
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

        if (!$source instanceof TrackingSource || !$source->get('isActive') || $source->isInternalKind()) {
            return false;
        }

        return $this->originAllowed($source, $origin);
    }

    private function originAllowed(TrackingSource $source, string $origin): bool
    {
        $allowedRaw = (string) ($source->get('allowedOrigins') ?? '');

        if ($allowedRaw === '') {
            // Website sources with no allow-list are deliberately restrictive
            // for browsers. (Save-validation makes this state unreachable for
            // new rows; kept as defence-in-depth.)
            return $source->get('kind') !== TrackingSource::KIND_WEBSITE;
        }

        $allowList = array_values(array_filter(
            array_map('trim', preg_split('/\R/', $allowedRaw) ?: []),
            fn($v) => $v !== '',
        ));

        return in_array($origin, $allowList, true);
    }

    /**
     * Constant-time HMAC-SHA256 verification for the trusted path.
     * Returns an error string, or null when valid.
     */
    private function verifySignature(TrackingSource $source, string $rawBody, ?string $signature): ?string
    {
        $stored = (string) ($source->get('signingSecret') ?? '');

        if ($stored === '') {
            // Save-validation makes this unreachable for new rows.
            return 'source has no signing secret configured';
        }

        try {
            $secret = $this->crypt->decrypt($stored);
        } catch (Throwable) {
            // Pre-encryption legacy row: fall back to the raw stored value.
            $secret = $stored;
        }

        if ($secret === '') {
            $secret = $stored;
        }

        if (!is_string($signature) || $signature === '') {
            return 'missing X-Tracking-Signature header';
        }

        $provided = strtolower(str_starts_with($signature, 'sha256=') ? substr($signature, 7) : $signature);
        $expected = hash_hmac('sha256', $rawBody, $secret);

        if (!hash_equals($expected, $provided)) {
            return 'invalid signature';
        }

        return null;
    }

    /**
     * Identity resolution, in decreasing order of trust:
     *   1. contactId    — trusted path only; must exist within the tenant.
     *   2. contactToken — both paths: a CRM-minted, HMAC-signed ContactToken
     *                     (short-link handoff: tracker.js forwards the
     *                     `mstx_c` param). Self-authenticating — we minted
     *                     it — but its embedded tenant must match the
     *                     source's tenant.
     *   3. email        — trusted path: accepted as-is.
     *                     public path: when identityVerificationSecret is
     *                     configured, requires a valid body-borne HMAC
     *                     (identitySignature [+ identityTimestamp], 24h
     *                     replay window); otherwise accepted as a soft,
     *                     Mixpanel-style claim.
     *   4. none         — the event stays anonymous (anonymousId only).
     *
     * @param array<string, mixed> $data
     */
    private function resolveContact(TrackingSource $source, array $data, bool $trusted, string $tenantId): ?object
    {
        $repo = $this->entityManager->getRDBRepository('Contact');

        if ($trusted) {
            $contactId = $data['contactId'] ?? null;

            if (is_string($contactId) && $contactId !== '') {
                $contact = $repo
                    ->where(['id' => $contactId, 'tenantId' => $tenantId, 'deleted' => false])
                    ->findOne();

                if ($contact) {
                    return $contact;
                }

                $this->log->warning("TrackingEventIngester: payload contactId={$contactId} not found in tenant={$tenantId}; falling back to email/anonymous.");
            }
        }

        $tokenRaw = $data['contactToken'] ?? null;

        if (is_string($tokenRaw) && $tokenRaw !== '') {
            $tokenData = $this->contactToken->verify($tokenRaw);

            if ($tokenData !== null && $tokenData['tenantId'] === $tenantId) {
                $contact = $repo
                    ->where(['id' => $tokenData['contactId'], 'tenantId' => $tenantId, 'deleted' => false])
                    ->findOne();

                if ($contact) {
                    return $contact;
                }
            }

            // Invalid/expired/foreign token: degrade to email/anonymous.
        }

        $email = $data['email'] ?? null;

        if (!is_string($email) || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        if (!$trusted && !$this->identityClaimAccepted($source, $email, $data)) {
            return null;
        }

        return $repo
            ->where(['emailAddress' => $email, 'tenantId' => $tenantId, 'deleted' => false])
            ->order('modifiedAt', 'DESC')
            ->findOne();
    }

    /**
     * @param array<string, mixed> $data
     */
    private function identityClaimAccepted(TrackingSource $source, string $email, array $data): bool
    {
        $stored = (string) ($source->get('identityVerificationSecret') ?? '');

        if ($stored === '') {
            // No verification configured — accept the claim (soft trust).
            return true;
        }

        try {
            $secret = $this->crypt->decrypt($stored);
        } catch (Throwable) {
            $secret = $stored;
        }

        $signature = $data['identitySignature'] ?? null;

        if (!is_string($signature) || $signature === '') {
            $this->log->info("TrackingEventIngester: unverified identify for source={$source->getId()} ignored (no identitySignature).");

            return false;
        }

        $timestamp = $data['identityTimestamp'] ?? null;

        if (is_numeric($timestamp)) {
            $timestamp = (int) $timestamp;

            if (abs(time() - $timestamp) > self::IDENTITY_SIGNATURE_MAX_AGE) {
                $this->log->info("TrackingEventIngester: identify signature outside replay window for source={$source->getId()}; ignored.");

                return false;
            }

            $signed = $email . ':' . $timestamp;
        } else {
            $signed = $email;
        }

        if (!hash_equals(hash_hmac('sha256', $signed, $secret), strtolower($signature))) {
            $this->log->warning("TrackingEventIngester: invalid identify signature for source={$source->getId()}; identity claim ignored.");

            return false;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $attributes
     * @param array<string, mixed> $data
     */
    private function applyParent(array &$attributes, array $data): void
    {
        $parentType = $data['parentType'] ?? null;
        $parentId = $data['parentId'] ?? null;

        if (
            is_string($parentType) && in_array($parentType, TrackingEventPersister::PARENT_TYPES, true) &&
            is_string($parentId) && $parentId !== ''
        ) {
            $attributes['parentType'] = $parentType;
            $attributes['parentId'] = $parentId;
        }
    }

    private function resolveOccurredAt(mixed $raw, DateTimeImmutable $now): string
    {
        if (is_string($raw) && $raw !== '') {
            try {
                $parsed = new DateTimeImmutable($raw);
                $parsed = $parsed->setTimezone(new DateTimeZone('UTC'));

                // Client clocks drift; never accept events from the future.
                if ($parsed > $now) {
                    $parsed = $now;
                }

                return $parsed->format('Y-m-d H:i:s');
            } catch (Throwable) {
                // fall through to server clock
            }
        }

        return $now->format('Y-m-d H:i:s');
    }

    private function str(mixed $value, int $maxLength): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        return mb_substr($value, 0, $maxLength);
    }

    /**
     * Path component of a URL, for name context ('/pricing'). Root path
     * yields null (adds no information).
     */
    private function urlPath(?string $url): ?string
    {
        if ($url === null) {
            return null;
        }

        $path = parse_url($url, PHP_URL_PATH);

        if (!is_string($path) || $path === '' || $path === '/') {
            return null;
        }

        return $path;
    }

    /**
     * Rightmost X-Forwarded-For hop = the address appended by our own edge
     * proxy (Traefik / Cloudflare tunnel) — the only entry a public client
     * cannot forge by prepending values.
     */
    private function resolveClientIp(?string $forwardedFor): ?string
    {
        if ($forwardedFor === null || trim($forwardedFor) === '') {
            return null;
        }

        $parts = array_values(array_filter(array_map('trim', explode(',', $forwardedFor)), fn($v) => $v !== ''));

        return $parts === [] ? null : end($parts);
    }

    /**
     * Failure/skip bookkeeping on the source row (status + error + timestamp,
     * no counter bump), mapped to the caller-facing IngestResult.
     */
    private function reject(
        TrackingSource $source,
        string $status,
        string $error,
        ?IngestResult $result = null,
    ): IngestResult {
        $this->persister->recordRejection($source, $status, $error);

        return $result ?? IngestResult::badRequest($error);
    }
}

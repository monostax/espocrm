<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureTrackingEvent\Services;

use DateTimeImmutable;
use DateTimeZone;
use Espo\Core\Job\JobSchedulerFactory;
use Espo\Core\Utils\Log;
use Espo\Modules\FeatureTrackingEvent\Entities\TrackingEvent;
use Espo\Modules\FeatureTrackingEvent\Entities\TrackingLink;
use Espo\Modules\FeatureTrackingEvent\Entities\TrackingSource;
use Espo\Modules\FeatureTrackingEvent\Jobs\AnonymousStitcher;
use Espo\ORM\EntityManager;
use Throwable;

/**
 * Joins inbound WhatsApp conversations to the web click / session that
 * produced them — the receive side of the tintim.app technique.
 *
 * Called by the Chatwoot module's SyncConversationsFromChatwoot job
 * (lazily by FQCN, mirroring the FeatureMetaConversionsApi precedent, so
 * that module carries no hard dependency on this one). Two match tiers,
 * best first; ctwa_clid (Meta click-to-WhatsApp ads) is handled separately
 * by FeatureMetaConversionsApi:
 *
 *   TOKEN — every newly-synced incoming message is scanned for a
 *   zero-width payload (ZeroWidthCodec) carrying the anonymousId minted at
 *   click time by TrackingLinkRedirector (or embedded client-side by
 *   tracker.js's WhatsApp link decorator). Exact, works at any point in
 *   the customer lifecycle: each new click mints a new id, so repeat
 *   clicks produce fresh linked events. One linked event per anonymousId
 *   (idempotent across re-syncs; a re-sent identical message is a no-op).
 *
 *   TIME WINDOW — when a NEW conversation starts with no token (lead
 *   erased the pre-filled text, or the target had no text param to embed
 *   into): recent wa.me-target TrackingLink clicks whose destination
 *   number matches the receiving inbox are candidates; the nearest
 *   unconsumed, non-bot click within MATCH_WINDOW_MINUTES wins. Marked
 *   payload.matchType = "time_window" (vs "token") so downstream CAPI /
 *   offline-conversion dispatch can decide how much to trust it.
 *
 * The linked event copies the origin click's attribution snapshot
 * (fbclid/utm_*) and trackingLink so it is self-sufficient for downstream
 * dispatch, resolves its source from the origin event (same tenant
 * enforced), and schedules AnonymousStitcher when a Contact is known —
 * which retroactively assigns the whole anonymous web history.
 *
 * Both public methods NEVER throw — attribution must never break a sync.
 */
class WhatsAppAttributionLinker
{
    public const EVENT_CODE = 'whatsapp_conversation_linked';

    public const MATCH_WINDOW_MINUTES = 15;

    /** Message timestamps come from Chatwoot; allow small clock skew. */
    private const CLOCK_SKEW_SECONDS = 120;

    /** anonymousId shapes we mint/accept: lnk_ hex, UUID, legacy hex. */
    private const ANON_ID_PATTERN = '/^[A-Za-z0-9_\-.]{8,64}$/';

    private const CANDIDATE_FETCH_LIMIT = 20;

    public function __construct(
        private EntityManager $entityManager,
        private Log $log,
        private TrackingEventPersister $persister,
        private JobSchedulerFactory $jobSchedulerFactory,
        private TrackingEventNameBuilder $nameBuilder,
    ) {}

    /**
     * TOKEN tier: extract a zero-width payload from an inbound message and
     * record the linked event. Returns true when an event was recorded.
     *
     * @param ?string $contactId CRM Contact resolved for the sender
     *   (ContactReconciler's denormalized id), verified against the tenant
     *   here before use.
     * @param array<string, mixed> $context Optional bookkeeping merged into
     *   the event payload: wamid, chatwootMessageId, chatwootConversationId,
     *   occurredAt (message timestamp, 'Y-m-d H:i:s' UTC).
     */
    public function linkFromMessageContent(
        string $content,
        ?string $contactId,
        string $tenantId,
        array $context = [],
    ): bool {
        try {
            $anonymousId = ZeroWidthCodec::extract($content);

            if ($anonymousId === null || preg_match(self::ANON_ID_PATTERN, $anonymousId) !== 1) {
                return false;
            }

            if ($tenantId === '' || $this->isConsumed($anonymousId, $tenantId)) {
                return false;
            }

            $origin = $this->findOriginEvent($anonymousId, $tenantId);

            if ($origin === null) {
                $this->log->info(
                    "WhatsAppAttributionLinker: token anonymousId={$anonymousId} has no origin event "
                    . "in tenant={$tenantId}; skipping."
                );

                return false;
            }

            return $this->recordLinkedEvent($origin, 'token', $anonymousId, $contactId, $tenantId, $context);
        } catch (Throwable $e) {
            $this->log->error('WhatsAppAttributionLinker: token link failed — ' . $e->getMessage());

            return false;
        }
    }

    /**
     * TIME-WINDOW tier: for a NEW, tokenless conversation, match the
     * nearest recent wa.me click targeting the receiving inbox's number.
     * Returns true when an event was recorded.
     *
     * @param ?string $inboxPhone The receiving inbox's number (any format).
     * @param ?string $startedAt Conversation start, 'Y-m-d H:i:s' UTC
     *   (defaults to now).
     * @param array<string, mixed> $context See linkFromMessageContent().
     */
    public function linkByTimeWindow(
        ?string $inboxPhone,
        ?string $contactId,
        string $tenantId,
        ?string $startedAt,
        array $context = [],
    ): bool {
        try {
            if ($tenantId === '') {
                return false;
            }

            $digits = preg_replace('/\D+/', '', (string) $inboxPhone) ?? '';

            if (strlen($digits) < 8) {
                return false;
            }

            $linkIds = $this->waLinkIdsForPhone($digits, $tenantId);

            if ($linkIds === []) {
                return false;
            }

            $start = $this->parseUtc($startedAt) ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));

            $click = $this->findMatchableClick($linkIds, $tenantId, $start);

            if ($click === null) {
                return false;
            }

            $anonymousId = (string) $click->get('anonymousId');

            return $this->recordLinkedEvent($click, 'time_window', $anonymousId, $contactId, $tenantId, $context);
        } catch (Throwable $e) {
            $this->log->error('WhatsAppAttributionLinker: time-window link failed — ' . $e->getMessage());

            return false;
        }
    }

    /**
     * One linked event per anonymousId: dedupes re-synced/re-sent tokens
     * AND stops the time-window matcher from double-consuming a click.
     */
    private function isConsumed(string $anonymousId, string $tenantId): bool
    {
        $existing = $this->entityManager
            ->getRDBRepository(TrackingEvent::ENTITY_TYPE)
            ->where([
                'code' => self::EVENT_CODE,
                'anonymousId' => $anonymousId,
                'tenantId' => $tenantId,
                'deleted' => false,
            ])
            ->findOne();

        return $existing !== null;
    }

    /**
     * The visitor's most recent prior event under this anonymousId — the
     * short-link click, or any tracker.js event when the id was embedded
     * client-side. Carries the source, attribution and link context the
     * linked event inherits.
     */
    private function findOriginEvent(string $anonymousId, string $tenantId): ?TrackingEvent
    {
        $origin = $this->entityManager
            ->getRDBRepository(TrackingEvent::ENTITY_TYPE)
            ->where([
                'anonymousId' => $anonymousId,
                'tenantId' => $tenantId,
                'code!=' => self::EVENT_CODE,
                'deleted' => false,
            ])
            ->order('occurredAt', 'DESC')
            ->findOne();

        return $origin instanceof TrackingEvent ? $origin : null;
    }

    /**
     * Active links in the tenant whose WhatsApp destination number matches
     * the receiving inbox. Targets are parsed in PHP (the number hides in
     * the URL path/query — not queryable), but tenants hold few links.
     *
     * @return list<string>
     */
    private function waLinkIdsForPhone(string $digits, string $tenantId): array
    {
        $links = $this->entityManager
            ->getRDBRepository(TrackingLink::ENTITY_TYPE)
            ->where([
                'tenantId' => $tenantId,
                'isActive' => true,
                'deleted' => false,
            ])
            ->find();

        $ids = [];

        foreach ($links as $link) {
            $phone = TrackingLink::whatsAppPhoneFromUrl((string) $link->get('targetUrl'));

            if ($phone === $digits) {
                $ids[] = $link->getId();
            }
        }

        return $ids;
    }

    /**
     * Nearest unconsumed human click on the matching links within the
     * window before the conversation start.
     *
     * @param list<string> $linkIds
     */
    private function findMatchableClick(array $linkIds, string $tenantId, DateTimeImmutable $start): ?TrackingEvent
    {
        $windowStart = $start->modify('-' . self::MATCH_WINDOW_MINUTES . ' minutes');
        $windowEnd = $start->modify('+' . self::CLOCK_SKEW_SECONDS . ' seconds');

        $candidates = $this->entityManager
            ->getRDBRepository(TrackingEvent::ENTITY_TYPE)
            ->where([
                'trackingLinkId' => $linkIds,
                'tenantId' => $tenantId,
                'code!=' => self::EVENT_CODE,
                'occurredAt>=' => $windowStart->format('Y-m-d H:i:s'),
                'occurredAt<=' => $windowEnd->format('Y-m-d H:i:s'),
                'deleted' => false,
            ])
            ->order('occurredAt', 'DESC') // nearest to the conversation first
            ->limit(0, self::CANDIDATE_FETCH_LIMIT)
            ->find();

        foreach ($candidates as $candidate) {
            if (!$candidate instanceof TrackingEvent) {
                continue;
            }

            $anonymousId = $candidate->get('anonymousId');

            if (!is_string($anonymousId) || $anonymousId === '') {
                continue;
            }

            $payload = $this->toArray($candidate->get('payload'));

            if (($payload['isLikelyBot'] ?? false) === true) {
                continue; // unfurl-preview GETs are recorded but never matched
            }

            if ($this->isConsumed($anonymousId, $tenantId)) {
                continue;
            }

            return $candidate;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function recordLinkedEvent(
        TrackingEvent $origin,
        string $matchType,
        string $anonymousId,
        ?string $contactId,
        string $tenantId,
        array $context,
    ): bool {
        $source = $this->entityManager->getEntityById(
            TrackingSource::ENTITY_TYPE,
            (string) ($origin->get('trackingSourceId') ?? ''),
        );

        if (
            !$source instanceof TrackingSource
            || !$source->get('isActive')
            || $source->isInternalKind()
            || $source->get('tenantId') !== $tenantId
        ) {
            return false;
        }

        $type = $this->persister->resolveEventType($source, self::EVENT_CODE, $tenantId);

        if ($type === null || $type->get('isActive') === false) {
            return false; // tenant muted the code — opt-out
        }

        $teamsIds = $this->persister->resolveTeamsIds($type, $source, $tenantId);

        if ($teamsIds === []) {
            $this->log->error("WhatsAppAttributionLinker: no teams resolve for tenant={$tenantId}; skipping.");

            return false;
        }

        $verifiedContactId = $this->verifyContactId($contactId, $tenantId);

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $occurredAt = $this->parseUtc($this->strOrNull($context['occurredAt'] ?? null)) ?? $now;

        if ($occurredAt > $now) {
            $occurredAt = $now;
        }

        $payload = ['matchType' => $matchType];

        foreach (['wamid', 'chatwootMessageId', 'chatwootConversationId'] as $key) {
            $value = $context[$key] ?? null;

            if (is_int($value) || (is_string($value) && $value !== '')) {
                $payload[$key] = is_string($value) ? mb_substr($value, 0, 256) : $value;
            }
        }

        $originPayload = $this->toArray($origin->get('payload'));

        foreach (['slug', 'waPhone'] as $key) {
            if (is_string($originPayload[$key] ?? null)) {
                $payload[$key] = $originPayload[$key];
            }
        }

        $occurredAtString = $occurredAt->format('Y-m-d H:i:s');

        // Context fragment: the business WhatsApp number, else the link slug.
        $nameDetail = is_string($payload['waPhone'] ?? null)
            ? '+' . $payload['waPhone']
            : ($payload['slug'] ?? null);

        $attributes = [
            'name' => $this->nameBuilder->build(
                self::EVENT_CODE, $tenantId, $type->get('name'), $nameDetail),
            'code' => self::EVENT_CODE,
            'occurredAt' => $occurredAtString,
            'receivedAt' => $now->format('Y-m-d H:i:s'),
            'status' => TrackingEvent::STATUS_RECEIVED,
            'channel' => TrackingEvent::CHANNEL_SERVER,
            'trackingSourceId' => $source->getId(),
            'trackingEventTypeId' => $type->getId(),
            'trackingLinkId' => $origin->get('trackingLinkId'),
            'anonymousId' => $anonymousId,
            'contactId' => $verifiedContactId,
            'payload' => (object) $payload,
            'attribution' => $origin->get('attribution'), // self-sufficient for CAPI dispatch
            'teamsIds' => $teamsIds,
            'tenantId' => $tenantId,
        ];

        try {
            $this->persister->persistEvent($attributes);
        } catch (Throwable $e) {
            $this->log->error("WhatsAppAttributionLinker: failed to persist linked event: " . $e->getMessage());

            return false;
        }

        $this->persister->bumpSourceCounters($source, $now);
        $this->persister->bumpTypeCounters($type, $now);

        if ($verifiedContactId !== null) {
            $this->jobSchedulerFactory
                ->create()
                ->setClassName(AnonymousStitcher::class)
                ->setData([
                    'contactId' => $verifiedContactId,
                    'anonymousId' => $anonymousId,
                    'tenantId' => $tenantId,
                ])
                ->setGroup('tracking-stitch-' . substr(md5($anonymousId), 0, 16))
                ->schedule();
        }

        $this->log->info(sprintf(
            'WhatsAppAttributionLinker: linked conversation to click (matchType=%s, anonymousId=%s, contact=%s).',
            $matchType,
            $anonymousId,
            $verifiedContactId ?? 'none',
        ));

        return true;
    }

    /** Same tenant-scoped verification the ingester applies to contactId. */
    private function verifyContactId(?string $contactId, string $tenantId): ?string
    {
        if (!is_string($contactId) || $contactId === '') {
            return null;
        }

        $contact = $this->entityManager
            ->getRDBRepository('Contact')
            ->where(['id' => $contactId, 'tenantId' => $tenantId, 'deleted' => false])
            ->findOne();

        if ($contact === null) {
            $this->log->warning(
                "WhatsAppAttributionLinker: contactId={$contactId} not found in tenant={$tenantId}; recording anonymous."
            );

            return null;
        }

        return $contact->getId();
    }

    private function parseUtc(?string $value): ?DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return (new DateTimeImmutable($value))->setTimezone(new DateTimeZone('UTC'));
        } catch (Throwable) {
            return null;
        }
    }

    private function strOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @return array<string, mixed> */
    private function toArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_object($value)) {
            return get_object_vars($value);
        }

        return [];
    }
}

<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureMetaConversionsApi\Services;

use Espo\Core\Utils\Log;
use Espo\Modules\FeatureMetaConversionsApi\Entities\MetaConversionEvent;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Throwable;

/**
 * Ingests Click-to-WhatsApp / Instagram conversion events from a Chatwoot
 * conversation's `additional_attributes.ctwa` block into MetaConversionEvent
 * rows. These rows are inbound, Meta-origin attribution records only — the
 * ingester NEVER dispatches them to the Conversions API directly.
 *
 * Capture shape (written by the Chatwoot-side patches — Component A):
 *   additional_attributes: {
 *     ctwa: {
 *       ctwa_clid: "...",            // whatsapp attribution key
 *       ig_sid: "...",               // instagram attribution key (alt)
 *       source_id: "...",            // WABA id / IG business account id
 *       channel: "whatsapp",         // optional; defaults inferred
 *       conversions: [
 *         { event_name, value, currency, wamid, event_time }, ...
 *       ]
 *     }
 *   }
 *
 * Idempotency: each conversion is keyed by `wamid` (unique). Re-syncing the
 * same conversation never double-creates.
 *
 * CAPI send trigger: the ONLY thing that dispatches to Meta is an Opportunity
 * stage change (Hooks\Opportunity\SendCapiOnStageChange -> CapiDispatcher).
 * Each ingested conversion creates/links an Opportunity; when that Opportunity
 * enters a stage with a metaCapiEventName (on a CAPI-enabled funnel), the
 * dispatcher detects the CTWA/IG attribution on the linked conversion and
 * emits the business_messaging event. No funnel/stage config => recorded for
 * attribution but not reported to Meta.
 *
 * Arrival events: when a conversation carries an ad attribution key
 * (`ctwa_clid` / `ig_sid`) but no conversions yet, a single "Received"
 * MetaConversionEvent is recorded (deduped via a synthetic
 * `ctwa:<clid>` / `igsid:<sid>` wamid) so ad-originated conversations are
 * tracked from arrival — mirroring MetaLeadgenEvent. Arrival rows still
 * create an Opportunity.
 *
 * This service intentionally does NOT mutate Chatwoot; it only reads the
 * already-captured attributes and produces CRM-side records.
 */
class ConversionEventIngester
{
    public function __construct(
        private EntityManager $entityManager,
        private OpportunityFactory $opportunityFactory,
        private Log $log,
    ) {}

    /**
     * @param array<string, mixed> $additionalAttributes The conversation's
     *        Chatwoot `additional_attributes` (raw).
     * @param Entity $chatwootConversation The CRM ChatwootConversation entity.
     * @param Entity|null $cwtContact The CRM ChatwootContact (carries the
     *        reconciled Espo Contact via its `contact` link).
     * @param string|null $tenantId Tenant derived from the ChatwootAccount.
     * @param array<string> $teamsIds Teams from the ChatwootAccount.
     *
     * @return int Number of new MetaConversionEvent rows created.
     */
    public function ingest(
        array $additionalAttributes,
        Entity $chatwootConversation,
        ?Entity $cwtContact,
        ?string $tenantId,
        array $teamsIds,
    ): int {
        $ctwa = $additionalAttributes['ctwa'] ?? null;

        if (!is_array($ctwa)) {
            return 0;
        }

        $channel = $this->resolveChannel($ctwa);
        $ctwaClid = $this->str($ctwa['ctwa_clid'] ?? null);
        $igSid = $this->str($ctwa['ig_sid'] ?? $ctwa['igsid'] ?? null);
        $sourceId = $this->str(
            $ctwa['source_id']
            ?? $ctwa['waba_id']
            ?? $ctwa['whatsapp_business_account_id']
            ?? $ctwa['instagram_business_account_id']
            ?? null
        );

        // Attribution key must be present for the resolved channel.
        $attribution = $channel === 'instagram' ? $igSid : $ctwaClid;

        $contactId = $cwtContact?->get('contactId');
        $created = 0;

        // Arrival event: the conversation originated from a Click-to-WhatsApp /
        // Instagram ad. Record a "Received" MetaConversionEvent (and Opportunity)
        // even before any Purchase/Lead lands — mirrors MetaLeadgenEvent. This
        // row is for attribution only and is never dispatched to the CAPI.
        if ($sourceId !== null && $attribution !== null) {
            $created += $this->ingestArrivalEvent(
                $channel,
                $sourceId,
                $ctwaClid,
                $igSid,
                $chatwootConversation,
                $contactId,
                $tenantId,
                $teamsIds,
            );
        }

        $conversions = $ctwa['conversions'] ?? null;

        if (!is_array($conversions) || $conversions === []) {
            return $created;
        }

        if ($sourceId === null || $attribution === null) {
            $this->log->info(sprintf(
                'MetaCapi ingester: conversation %s ctwa missing sourceId/attribution (channel=%s); skipping %d conversion(s).',
                (string) $chatwootConversation->getId(),
                $channel,
                count($conversions),
            ));

            return $created;
        }

        foreach ($conversions as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $wamid = $this->str($entry['wamid'] ?? $entry['id'] ?? null);

            if ($wamid === null) {
                $this->log->warning(sprintf(
                    'MetaCapi ingester: conversation %s conversion entry has no wamid; skipping (cannot dedupe).',
                    (string) $chatwootConversation->getId(),
                ));
                continue;
            }

            // Idempotency: skip if we already recorded this wamid.
            $existing = $this->entityManager
                ->getRDBRepository(MetaConversionEvent::ENTITY_TYPE)
                ->where(['wamid' => $wamid])
                ->findOne();

            if ($existing) {
                continue;
            }

            $conversion = $this->createConversionEntity(
                $entry,
                $channel,
                $sourceId,
                $ctwaClid,
                $igSid,
                $wamid,
                $chatwootConversation,
                $contactId,
                $tenantId,
                $teamsIds,
            );

            try {
                // Persist first (idempotency anchor). Cascade not used here —
                // tenancy is set explicitly below.
                $this->entityManager->saveEntity($conversion);
            } catch (Throwable $e) {
                // Unique(wamid) race: another worker inserted it. Treat as done.
                $this->log->info(sprintf(
                    'MetaCapi ingester: wamid %s insert failed (likely concurrent): %s',
                    $wamid,
                    $e->getMessage(),
                ));
                continue;
            }

            $created++;

            // Auto-create an Opportunity (linked to the reconciled Contact)
            // per the matching source's config, and link it back onto the
            // conversion. The Opportunity save fires SendCapiOnStageChange,
            // which is the SOLE trigger for any Meta CAPI send: when the
            // landing stage has a metaCapiEventName and the funnel is
            // CAPI-enabled, dispatch() detects this CTWA/IG-attributed
            // Opportunity and emits a business_messaging event keyed off this
            // conversion. The conversion itself is never dispatched directly —
            // it is an inbound, Meta-origin attribution record only.
            $opportunity = $this->opportunityFactory->createForConversion(
                $conversion,
                $contactId,
                $tenantId,
                $teamsIds,
            );

            if ($opportunity) {
                try {
                    $conversion->set('opportunityId', $opportunity->getId());
                    $this->entityManager->saveEntity($conversion, ['skipHooks' => true, 'silent' => true]);
                } catch (Throwable $e) {
                    $this->log->error(sprintf(
                        'MetaCapi ingester: failed to link Opportunity %s onto conversion %s: %s',
                        (string) $opportunity->getId(),
                        (string) $conversion->getId(),
                        $e->getMessage(),
                    ));
                }
            }
        }

        return $created;
    }

    /**
     * Record an arrival-only MetaConversionEvent for an ad-originated
     * conversation (ctwaClid / igSid present), deduped by a synthetic wamid
     * derived from the attribution key. Creates an Opportunity like the
     * conversion path, but never dispatches to the Conversions API.
     *
     * @param array<string> $teamsIds
     *
     * @return int 1 if a new arrival row was created, 0 otherwise.
     */
    private function ingestArrivalEvent(
        string $channel,
        string $sourceId,
        ?string $ctwaClid,
        ?string $igSid,
        Entity $chatwootConversation,
        ?string $contactId,
        ?string $tenantId,
        array $teamsIds,
    ): int {
        // Synthetic, stable idempotency key. Reuses the unique(wamid) index so
        // re-syncing the same conversation never double-creates the arrival row.
        $attribution = $channel === MetaConversionEvent::CHANNEL_INSTAGRAM ? $igSid : $ctwaClid;
        $prefix = $channel === MetaConversionEvent::CHANNEL_INSTAGRAM ? 'igsid' : 'ctwa';
        $wamid = $prefix . ':' . $attribution;

        $existing = $this->entityManager
            ->getRDBRepository(MetaConversionEvent::ENTITY_TYPE)
            ->where(['wamid' => $wamid])
            ->findOne();

        if ($existing) {
            return 0;
        }

        /** @var MetaConversionEvent $conversion */
        $conversion = $this->entityManager->getNewEntity(MetaConversionEvent::ENTITY_TYPE);

        $conversion->set('eventName', MetaConversionEvent::EVENT_CONTACT);
        $conversion->set('channel', $channel);
        $conversion->set('sourceId', $sourceId);
        $conversion->set('ctwaClid', $ctwaClid);
        $conversion->set('igSid', $igSid);
        $conversion->set('wamid', $wamid);
        $conversion->set('eventTime', date('Y-m-d H:i:s'));
        $conversion->set('rawPayload', ['source' => 'ctwa_arrival', 'attribution' => $attribution]);
        $conversion->set('chatwootConversationId', $chatwootConversation->getId());
        $conversion->set('name', sprintf('%s · %s', MetaConversionEvent::EVENT_CONTACT, $sourceId));

        if ($contactId) {
            $conversion->set('contactId', $contactId);
        }

        if ($tenantId) {
            $conversion->set('tenantId', $tenantId);
        }

        if (!empty($teamsIds)) {
            $conversion->set('teamsIds', array_values(array_unique($teamsIds)));
        }

        try {
            $this->entityManager->saveEntity($conversion);
        } catch (Throwable $e) {
            // Unique(wamid) race: another worker inserted it. Treat as done.
            $this->log->info(sprintf(
                'MetaCapi ingester: arrival wamid %s insert failed (likely concurrent): %s',
                $wamid,
                $e->getMessage(),
            ));

            return 0;
        }

        // Auto-create an Opportunity (linked to the reconciled Contact) per the
        // matching source's config — same as the conversion path. Never throws.
        $opportunity = $this->opportunityFactory->createForConversion(
            $conversion,
            $contactId,
            $tenantId,
            $teamsIds,
        );

        if ($opportunity) {
            try {
                $conversion->set('opportunityId', $opportunity->getId());
                $this->entityManager->saveEntity($conversion, ['skipHooks' => true, 'silent' => true]);
            } catch (Throwable $e) {
                $this->log->error(sprintf(
                    'MetaCapi ingester: failed to link Opportunity %s onto arrival conversion %s: %s',
                    (string) $opportunity->getId(),
                    (string) $conversion->getId(),
                    $e->getMessage(),
                ));
            }
        }

        return 1;
    }

    private function createConversionEntity(
        array $entry,
        string $channel,
        string $sourceId,
        ?string $ctwaClid,
        ?string $igSid,
        string $wamid,
        Entity $chatwootConversation,
        ?string $contactId,
        ?string $tenantId,
        array $teamsIds,
    ): MetaConversionEvent {
        /** @var MetaConversionEvent $conversion */
        $conversion = $this->entityManager->getNewEntity(MetaConversionEvent::ENTITY_TYPE);

        $eventName = $this->normalizeEventName($this->str($entry['event_name'] ?? null));
        $value = isset($entry['value']) && is_numeric($entry['value']) ? (float) $entry['value'] : null;
        $currency = $this->str($entry['currency'] ?? null);
        $eventTime = $this->resolveEventTime($entry);

        $conversion->set('eventName', $eventName);
        $conversion->set('channel', $channel);
        $conversion->set('sourceId', $sourceId);
        $conversion->set('ctwaClid', $ctwaClid);
        $conversion->set('igSid', $igSid);
        $conversion->set('wamid', $wamid);
        $conversion->set('value', $value);
        $conversion->set('currency', $currency);
        $conversion->set('eventTime', date('Y-m-d H:i:s', $eventTime));
        $conversion->set('rawPayload', $entry);
        $conversion->set('chatwootConversationId', $chatwootConversation->getId());
        $conversion->set('name', sprintf('%s · %s', $eventName, $sourceId));

        if ($contactId) {
            $conversion->set('contactId', $contactId);
        }

        if ($tenantId) {
            $conversion->set('tenantId', $tenantId);
        }

        if (!empty($teamsIds)) {
            $conversion->set('teamsIds', array_values(array_unique($teamsIds)));
        }

        return $conversion;
    }

    private function resolveChannel(array $ctwa): string
    {
        $raw = strtolower($this->str($ctwa['channel'] ?? '') ?? '');

        if ($raw === 'instagram' || str_contains($raw, 'instagram')) {
            return MetaConversionEvent::CHANNEL_INSTAGRAM;
        }

        // If only an ig_sid is present (no ctwa_clid), infer Instagram.
        if (empty($ctwa['ctwa_clid']) && !empty($ctwa['ig_sid'])) {
            return MetaConversionEvent::CHANNEL_INSTAGRAM;
        }

        return MetaConversionEvent::CHANNEL_WHATSAPP;
    }

    private function normalizeEventName(?string $name): string
    {
        $allowed = ['Purchase', 'LeadSubmitted', 'AddToCart', 'InitiateCheckout', 'ViewContent'];

        if ($name === null || $name === '') {
            return 'Purchase';
        }

        foreach ($allowed as $candidate) {
            if (strcasecmp($candidate, $name) === 0) {
                return $candidate;
            }
        }

        return 'Other';
    }

    private function resolveEventTime(array $entry): int
    {
        $raw = $entry['event_time'] ?? $entry['timestamp'] ?? null;

        if (is_int($raw) && $raw > 0) {
            return $raw;
        }

        if (is_string($raw) && $raw !== '') {
            if (ctype_digit($raw)) {
                return (int) $raw;
            }
            $ts = strtotime($raw);
            if ($ts !== false) {
                return $ts;
            }
        }

        return time();
    }

    private function str(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }

        $s = trim((string) $v);

        return $s === '' ? null : $s;
    }
}

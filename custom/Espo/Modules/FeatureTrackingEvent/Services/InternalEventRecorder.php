<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureTrackingEvent\Services;

use DateTimeImmutable;
use DateTimeZone;
use Espo\Core\Utils\Log;
use Espo\Modules\FeatureTrackingEvent\Entities\TrackingEvent;
use Espo\Modules\FeatureTrackingEvent\Entities\TrackingEventType;
use Espo\Modules\FeatureTrackingEvent\Entities\TrackingSource;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Throwable;

/**
 * Records tracking events emitted by the CRM itself (e.g. Opportunity stage
 * changes) — the in-process counterpart of TrackingEventIngester.
 *
 * Why not POST to the ingest endpoint? The endpoint's HMAC / origin / rate
 * limit machinery authenticates EXTERNAL callers crossing the trust
 * boundary. An Espo hook is already inside that boundary — looping HTTP
 * back to ourselves would only add secret management, a network hop per
 * save and rate-limit interference. This service goes straight to the
 * shared TrackingEventPersister instead.
 *
 * Source model: OPT-IN. The tenant (or an admin) creates a TrackingSource
 * with kind=CRM — that single row is the enable switch for internal CRM
 * event tracking. The recorder resolves it by (kind=CRM, tenantId); no
 * source (or an inactive one) means recording is silently skipped.
 * ValidateSingleCrmSourcePerTenant enforces at most one CRM source per
 * tenant, so events can never be split or duplicated across sources, and
 * the ingester refuses kind=CRM over HTTP entirely (isInternalKind()).
 *
 * Tenant control levers (no code changes needed):
 *   - no kind=CRM source / deactivate it          -> all internal events off;
 *   - deactivate a single TrackingEventType row   -> that code off;
 *   - disable allowUnknownEventCode on the source -> only pre-created codes.
 *
 * Auto-created event types use category=System (not Custom) so internal
 * lifecycle events are distinguishable in cross-tenant reporting.
 *
 * record() NEVER throws — internal tracking must never break a user's save.
 * Failures are logged and swallowed (the caller gets null).
 */
class InternalEventRecorder
{
    public function __construct(
        private EntityManager $entityManager,
        private Log $log,
        private TrackingEventPersister $persister,
        private TrackingEventNameBuilder $nameBuilder,
    ) {}

    /**
     * Record an internal event for a tenant. Returns the new TrackingEvent
     * id, or null when skipped/failed (opt-out, misconfiguration, error).
     *
     * @param array{
     *     contactId?: ?string,
     *     parentType?: ?string,
     *     parentId?: ?string,
     *     value?: mixed,
     *     currency?: ?string,
     *     properties?: array<string, mixed>,
     *     detail?: ?string,
     * } $options 'detail' is a short human context fragment appended to the
     *     event name (e.g. the opportunity name).
     */
    public function record(string $tenantId, string $code, array $options = []): ?string
    {
        try {
            return $this->recordInternal($tenantId, $code, $options);
        } catch (Throwable $e) {
            $this->log->error(
                "InternalEventRecorder: failed to record '{$code}' for tenant={$tenantId}: " . $e->getMessage()
            );

            return null;
        }
    }

    /**
     * Derive the tenant for an entity that carries tenancy via teams only
     * (Opportunity, Lead, ... have no tenantId column): teams ->
     * Tenant.baseUserTeam, requiring an unambiguous single match. Falls back
     * to the persisted entity_team relation when the in-memory entity has no
     * teamsIds loaded (partial updates).
     */
    public function resolveTenantIdForEntity(Entity $entity): ?string
    {
        $teamIds = [];

        try {
            $teamIds = $entity->getLinkMultipleIdList('teams') ?: [];
        } catch (Throwable) {
            $teamIds = [];
        }

        if ($teamIds === [] && $entity->hasId()) {
            try {
                $teams = $this->entityManager
                    ->getRDBRepository($entity->getEntityType())
                    ->getRelation($entity, 'teams')
                    ->find();

                foreach ($teams as $team) {
                    $teamIds[] = $team->getId();
                }
            } catch (Throwable) {
                // fall through with whatever we have
            }
        }

        if ($teamIds === []) {
            return null;
        }

        $tenants = $this->entityManager
            ->getRDBRepository('Tenant')
            ->where(['baseUserTeamId' => array_values(array_unique($teamIds))])
            ->find();

        $tenantIds = [];

        foreach ($tenants as $tenant) {
            $tenantIds[$tenant->getId()] = true;
        }

        if (count($tenantIds) === 1) {
            return array_key_first($tenantIds);
        }

        if (count($tenantIds) > 1) {
            $this->log->warning(sprintf(
                'InternalEventRecorder: %s %s resolves to multiple tenants via teams %s; skipping.',
                $entity->getEntityType(),
                $entity->hasId() ? $entity->getId() : '(new)',
                implode(',', $teamIds),
            ));
        }

        return null;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function recordInternal(string $tenantId, string $code, array $options): ?string
    {
        if ($tenantId === '') {
            return null;
        }

        $normalized = $this->persister->normalizeCode($code);

        if ($normalized === null) {
            $this->log->warning("InternalEventRecorder: invalid event code '{$code}'; skipping.");

            return null;
        }

        $source = $this->findSource($tenantId);

        if ($source === null) {
            // No active kind=CRM source for this tenant: internal tracking
            // is not enabled. Silent by design — no log spam per save.
            return null;
        }

        $type = $this->persister->resolveEventType(
            $source,
            $normalized,
            $tenantId,
            TrackingEventType::CATEGORY_SYSTEM,
        );

        // null: tenant disabled allowUnknownEventCode and never created the
        // code; isActive=false: tenant deactivated the code. Both = opt-out.
        if ($type === null || $type->get('isActive') === false) {
            return null;
        }

        $teamsIds = $this->persister->resolveTeamsIds($type, $source, $tenantId);

        if ($teamsIds === []) {
            $this->log->error("InternalEventRecorder: no teams resolve for tenant={$tenantId} code={$normalized}; skipping.");

            return null;
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $occurredAt = $now->format('Y-m-d H:i:s');

        $properties = is_array($options['properties'] ?? null) ? $options['properties'] : [];

        $detail = is_string($options['detail'] ?? null) && $options['detail'] !== ''
            ? $options['detail']
            : null;

        $attributes = [
            'name' => $this->nameBuilder->build($normalized, $tenantId, $type->get('name'), $detail),
            'code' => $normalized,
            'occurredAt' => $occurredAt,
            'receivedAt' => $occurredAt,
            'status' => TrackingEvent::STATUS_RECEIVED,
            'channel' => TrackingEvent::CHANNEL_SERVER,
            'trackingSourceId' => $source->getId(),
            'trackingEventTypeId' => $type->getId(),
            'payload' => (object) $properties,
            'teamsIds' => $teamsIds,
            'tenantId' => $tenantId,
        ];

        $contactId = $options['contactId'] ?? null;

        if (is_string($contactId) && $contactId !== '') {
            $attributes['contactId'] = $contactId;
        }

        $parentType = $options['parentType'] ?? null;
        $parentId = $options['parentId'] ?? null;

        if (
            is_string($parentType) && in_array($parentType, TrackingEventPersister::PARENT_TYPES, true) &&
            is_string($parentId) && $parentId !== ''
        ) {
            $attributes['parentType'] = $parentType;
            $attributes['parentId'] = $parentId;
        }

        if (is_numeric($options['value'] ?? null)) {
            $attributes['value'] = (float) $options['value'];
            $attributes['currency'] = in_array($options['currency'] ?? null, TrackingEventPersister::CURRENCIES, true)
                ? $options['currency']
                : '';
        }

        $event = $this->persister->persistEvent($attributes);

        $this->persister->bumpSourceCounters($source, $now);
        $this->persister->bumpTypeCounters($type, $now);

        return $event->getId();
    }

    /**
     * Resolve the tenant's user-created CRM (internal) source — the opt-in
     * switch for internal event tracking. ValidateSingleCrmSourcePerTenant
     * guarantees at most one non-deleted row per tenant; the createdAt
     * ordering makes the pick deterministic even if legacy duplicates
     * slipped in before that rule existed.
     */
    private function findSource(string $tenantId): ?TrackingSource
    {
        $source = $this->entityManager
            ->getRDBRepository(TrackingSource::ENTITY_TYPE)
            ->where([
                'kind' => TrackingSource::KIND_CRM,
                'tenantId' => $tenantId,
                'isActive' => true,
                'deleted' => false,
            ])
            ->order('createdAt')
            ->findOne();

        return $source instanceof TrackingSource ? $source : null;
    }
}

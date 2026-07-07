<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureTrackingEvent\Services;

use DateTimeImmutable;
use DateTimeZone;
use Espo\Core\Utils\Log;
use Espo\Modules\FeatureTrackingEvent\Entities\TrackingEvent;
use Espo\Modules\FeatureTrackingEvent\Entities\TrackingEventType;
use Espo\Modules\FeatureTrackingEvent\Entities\TrackingSource;
use Espo\ORM\EntityManager;
use Espo\ORM\Query\Part\Condition;
use Espo\ORM\Query\Part\Expression;
use Throwable;

/**
 * Shared persistence core for every TrackingEvent writer.
 *
 * Two producers use it:
 *   - TrackingEventIngester  — the HTTP ingestion pipeline (external events,
 *     trusted + public paths). Owns transport concerns: signatures, origins,
 *     rate limits, payload normalization.
 *   - InternalEventRecorder  — in-process events emitted by the CRM itself
 *     (e.g. Opportunity stage changes). No transport, no authentication:
 *     callers are already inside the trust boundary.
 *
 * What lives here (and ONLY here, so the two paths cannot drift):
 *   - event-code normalization,
 *   - TrackingEventType resolution with race-safe auto-create,
 *   - the teams fallback chain (type -> source -> Tenant.baseUserTeam),
 *   - silent event persistence (suppresses stream/notifications; Cascade*
 *     hooks early-return on silent). NOTE: saves deliberately do NOT use
 *     skipHooks — linkMultiple attributes (teamsIds) are persisted by the
 *     core FieldProcessing hook, so skipHooks would silently drop team
 *     assignment (and with it tenant isolation),
 *   - atomic `SET total = total + 1` counter bumps (no read-modify-write
 *     lost updates under load),
 *   - source status bookkeeping (lastEventStatus / lastEventError).
 */
class TrackingEventPersister
{
    public const CODE_PATTERN = '/^[a-z][a-z0-9_]{0,63}$/';

    public const CURRENCIES = ['USD', 'EUR', 'BRL', 'GBP', 'MXN', 'ARS'];

    public const PARENT_TYPES = ['Lead', 'Opportunity', 'Contact', 'Account'];

    /**
     * Ad-platform / campaign query params captured into TrackingEvent
     * attribution. Server-side mirror of tracker.js's ATTRIBUTION_PARAMS
     * (plain-JS SDK cannot import this — keep the two lists in sync).
     */
    public const ATTRIBUTION_PARAMS = [
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
        'utm_id',
        'gclid', 'wbraid', 'gbraid', 'fbclid', 'msclkid', 'ttclid',
    ];

    public function __construct(
        private EntityManager $entityManager,
        private Log $log,
    ) {}

    public function normalizeCode(mixed $raw): ?string
    {
        if (!is_string($raw)) {
            return null;
        }

        $code = strtolower(trim($raw));

        return preg_match(self::CODE_PATTERN, $code) === 1 ? $code : null;
    }

    /**
     * Resolve the TrackingEventType for (code, tenant), auto-creating a row
     * (with the given category) when the source allows it. Handles the
     * codeTenant unique-index race by re-fetching on duplicate key.
     */
    public function resolveEventType(
        TrackingSource $source,
        string $code,
        string $tenantId,
        string $category = TrackingEventType::CATEGORY_CUSTOM,
    ): ?TrackingEventType {
        $repo = $this->entityManager->getRDBRepository(TrackingEventType::ENTITY_TYPE);

        $type = $repo
            ->where(['code' => $code, 'tenantId' => $tenantId, 'deleted' => false])
            ->findOne();

        if ($type instanceof TrackingEventType) {
            return $type;
        }

        if (!$source->get('allowUnknownEventCode')) {
            return null;
        }

        try {
            /** @var TrackingEventType $type */
            $type = $this->entityManager->getNewEntity(TrackingEventType::ENTITY_TYPE);

            $type->set([
                'name' => $code,
                'code' => $code,
                'category' => $category,
                'isActive' => true,
                'teamsIds' => $this->sourceTeamsIds($source),
                'tenantId' => $tenantId,
            ]);

            // silent (no stream noise) but NOT skipHooks — see class docblock.
            $this->entityManager->saveEntity($type, ['silent' => true]);

            return $type;
        } catch (Throwable) {
            // Concurrent first-sight of the same code: unique index
            // codeTenant fired for the other request. Re-fetch.
            $type = $repo
                ->where(['code' => $code, 'tenantId' => $tenantId, 'deleted' => false])
                ->findOne();

            return $type instanceof TrackingEventType ? $type : null;
        }
    }

    /**
     * Teams for the new event row, in fallback order:
     *   1. the resolved TrackingEventType's teams,
     *   2. the TrackingSource's teams,
     *   3. the Tenant's baseUserTeam (the inverse of the
     *      AssignTenantFromTeam derivation — every tenant has one).
     *
     * @return list<string>
     */
    public function resolveTeamsIds(TrackingEventType $type, TrackingSource $source, string $tenantId): array
    {
        try {
            $teamsIds = $type->getLinkMultipleIdList('teams');
        } catch (Throwable) {
            $teamsIds = [];
        }

        if ($teamsIds !== []) {
            return array_values(array_unique($teamsIds));
        }

        $teamsIds = $this->sourceTeamsIds($source);

        if ($teamsIds !== []) {
            return $teamsIds;
        }

        return $this->tenantBaseTeamIds($tenantId);
    }

    /** @return list<string> */
    public function tenantBaseTeamIds(string $tenantId): array
    {
        try {
            $tenant = $this->entityManager->getEntityById('Tenant', $tenantId);
            $baseTeamId = $tenant?->get('baseUserTeamId');

            return is_string($baseTeamId) && $baseTeamId !== '' ? [$baseTeamId] : [];
        } catch (Throwable) {
            return [];
        }
    }

    /** @return list<string> */
    public function sourceTeamsIds(TrackingSource $source): array
    {
        try {
            return array_values(array_unique($source->getLinkMultipleIdList('teams')));
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Persist a fully-assembled event row. Throws on failure — callers
     * decide how a persistence error maps to their result type.
     *
     * @param array<string, mixed> $attributes
     */
    public function persistEvent(array $attributes): TrackingEvent
    {
        /** @var TrackingEvent $event */
        $event = $this->entityManager->getNewEntity(TrackingEvent::ENTITY_TYPE);

        $event->set($attributes);

        // silent: no stream/notification noise; Cascade* hooks early-return.
        // NOT skipHooks: the FieldProcessing common hook must run to
        // persist teamsIds (entity_team) — team ACL isolation depends on it.
        $this->entityManager->saveEntity($event, ['silent' => true]);

        return $event;
    }

    /**
     * Failure/skip bookkeeping on the source row: status + error + timestamp,
     * WITHOUT bumping totalEventsReceived (accepted events only). Runs as a
     * direct UPDATE so no hooks/streams fire.
     */
    public function recordRejection(TrackingSource $source, string $status, string $error): void
    {
        $this->updateSource($source, [
            'lastEventReceivedAt' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
            'lastEventStatus' => $status,
            'lastEventError' => $error,
        ]);
    }

    public function bumpSourceCounters(TrackingSource $source, DateTimeImmutable $now): void
    {
        $this->updateSource($source, [
            'totalEventsReceived' => Expression::add(Expression::column('totalEventsReceived'), 1),
            'lastEventReceivedAt' => $now->format('Y-m-d H:i:s'),
            'lastEventStatus' => TrackingSource::STATUS_ACCEPTED,
            'lastEventError' => null,
        ]);
    }

    public function bumpTypeCounters(TrackingEventType $type, DateTimeImmutable $now): void
    {
        try {
            $query = $this->entityManager
                ->getQueryBuilder()
                ->update()
                ->in(TrackingEventType::ENTITY_TYPE)
                ->set([
                    'totalEventsReceived' => Expression::add(Expression::column('totalEventsReceived'), 1),
                    'lastEventAt' => $now->format('Y-m-d H:i:s'),
                ])
                ->where(Condition::equal(Expression::column('id'), $type->getId()))
                ->build();

            $this->entityManager->getQueryExecutor()->execute($query);
        } catch (Throwable $e) {
            $this->log->warning('TrackingEventPersister: type counter bump failed — ' . $e->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $set
     */
    private function updateSource(TrackingSource $source, array $set): void
    {
        try {
            $query = $this->entityManager
                ->getQueryBuilder()
                ->update()
                ->in(TrackingSource::ENTITY_TYPE)
                ->set($set)
                ->where(Condition::equal(Expression::column('id'), $source->getId()))
                ->build();

            $this->entityManager->getQueryExecutor()->execute($query);
        } catch (Throwable $e) {
            $this->log->warning('TrackingEventPersister: source status update failed — ' . $e->getMessage());
        }
    }
}

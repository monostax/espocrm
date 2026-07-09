<?php
/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

declare(strict_types=1);

namespace Espo\Modules\FeatureTrackingEvent\Services;

use Espo\Core\Utils\Log;
use Espo\Modules\FeatureTrackingEvent\Entities\TrackingSource;
use Espo\ORM\EntityManager;
use Throwable;

/**
 * Idempotent find-or-create of the per-tenant kind=CRM TrackingSource —
 * the enable switch for internal CRM event tracking (see
 * InternalEventRecorder).
 *
 * Opt-OUT model: every tenant gets a CRM source automatically (on tenant
 * creation via the ProvisionCrmTrackingSource hook, and lazily on first
 * record() for tenants that predate auto-provisioning). A tenant opts out
 * by deactivating or deleting the row — provision() treats ANY existing
 * row (active, deactivated, or soft-deleted) as "already decided" and
 * never resurrects or duplicates it.
 *
 * provision() NEVER throws — it is called from hooks and from the
 * recorder's save path, where a provisioning failure must never break a
 * user's save. Failures are logged and null is returned.
 */
class CrmSourceProvisioner
{
    private const SOURCE_NAME = 'CRM';

    public function __construct(
        private EntityManager $entityManager,
        private Log $log,
    ) {}

    /**
     * Returns the ACTIVE kind=CRM source for the tenant, creating it when
     * the tenant never had one. Returns null when the tenant opted out
     * (an inactive or soft-deleted row exists) or creation failed.
     */
    public function provision(string $tenantId): ?TrackingSource
    {
        if ($tenantId === '') {
            return null;
        }

        try {
            return $this->provisionInternal($tenantId);
        } catch (Throwable $e) {
            $this->log->warning(
                "CrmSourceProvisioner: failed for tenant={$tenantId}: " . $e->getMessage()
            );

            return null;
        }
    }

    private function provisionInternal(string $tenantId): ?TrackingSource
    {
        // Mirrors InternalEventRecorder::findSource() (kind, tenantId,
        // isActive, ordered by createdAt for legacy-duplicate tolerance).
        $active = $this->entityManager
            ->getRDBRepository(TrackingSource::ENTITY_TYPE)
            ->where([
                'kind' => TrackingSource::KIND_CRM,
                'tenantId' => $tenantId,
                'isActive' => true,
            ])
            ->order('createdAt')
            ->findOne();

        if ($active instanceof TrackingSource) {
            return $active;
        }

        // Any row that EVER existed — deactivated or soft-deleted — is an
        // explicit tenant decision (opt-out). Respect it: do not recreate.
        $query = $this->entityManager
            ->getQueryBuilder()
            ->select()
            ->from(TrackingSource::ENTITY_TYPE)
            ->where([
                'kind' => TrackingSource::KIND_CRM,
                'tenantId' => $tenantId,
            ])
            ->withDeleted()
            ->build();

        $any = $this->entityManager
            ->getRDBRepository(TrackingSource::ENTITY_TYPE)
            ->clone($query)
            ->findOne();

        if ($any !== null) {
            return null;
        }

        $tenant = $this->entityManager->getEntityById('Tenant', $tenantId);

        if (!$tenant) {
            return null;
        }

        // Team-level ACL on TrackingSource: without the base team the
        // tenant could not see (or deactivate) their own source.
        $baseTeamId = $tenant->get('baseUserTeamId');
        $teamsIds = is_string($baseTeamId) && $baseTeamId !== '' ? [$baseTeamId] : [];

        // Non-silent save on purpose: keeps AssignTenantFromTeam (no-op,
        // tenantId already set) and ValidateSingleCrmSourcePerTenant (race
        // protection against a concurrent provision) in the loop.
        $source = $this->entityManager->createEntity(TrackingSource::ENTITY_TYPE, [
            'name' => self::SOURCE_NAME,
            'kind' => TrackingSource::KIND_CRM,
            'tenantId' => $tenantId,
            'isActive' => true,
            'teamsIds' => $teamsIds,
        ]);

        $this->log->info(
            "CrmSourceProvisioner: auto-provisioned kind=CRM TrackingSource for tenant={$tenantId}."
        );

        return $source instanceof TrackingSource ? $source : null;
    }
}

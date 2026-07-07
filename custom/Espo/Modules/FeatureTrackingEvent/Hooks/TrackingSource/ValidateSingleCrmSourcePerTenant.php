<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureTrackingEvent\Hooks\TrackingSource;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Hook\Hook\BeforeSave;
use Espo\Modules\FeatureTrackingEvent\Entities\TrackingSource;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\Option\SaveOptions;

/**
 * Enforces the CRM (internal) source contract: at most ONE kind=CRM source
 * per tenant, and it must belong to a tenant.
 *
 * Why one per tenant: InternalEventRecorder resolves the destination source
 * by (kind=CRM, tenantId) — a second row would be dead weight at best and,
 * if the lookup ever returned it, would split/duplicate the event stream.
 * The user creating this single source IS the opt-in for internal CRM event
 * tracking; deactivating it is the opt-out.
 *
 * Why tenant is required: a CRM source without a tenant can never be
 * resolved by the recorder — it would silently record nothing. Fail loudly
 * at save time instead ("assign a team").
 *
 * Runs at ORM level, order 10 — AFTER AssignTenantFromTeam (order 9) has
 * derived tenantId from the selected teams, so both creates and kind/team
 * edits are validated against the final tenant value. Skipped on silent
 * saves (system paths; AssignTenantFromTeam also early-returns there).
 *
 * Validation-by-exception from a BeforeSave ORM hook follows the
 * established pattern of Global\Hooks\Opportunity\ValidateStageFunnel.
 *
 * @implements BeforeSave<TrackingSource>
 */
class ValidateSingleCrmSourcePerTenant implements BeforeSave
{
    public static int $order = 10;

    public function __construct(
        private EntityManager $entityManager,
    ) {}

    /**
     * @throws BadRequest
     */
    public function beforeSave(Entity $entity, SaveOptions $options): void
    {
        if (!$entity instanceof TrackingSource) {
            return;
        }

        if ($options->get('silent')) {
            return;
        }

        if (!$entity->isInternalKind()) {
            return;
        }

        // Only validate when relevant attributes are in play — a counter
        // bump or unrelated edit must not re-trigger tenant derivation
        // requirements.
        if (
            !$entity->isNew() &&
            !$entity->isAttributeChanged('kind') &&
            !$entity->isAttributeChanged('tenantId') &&
            !$entity->isAttributeChanged('teamsIds')
        ) {
            return;
        }

        $tenantId = $entity->get('tenantId');

        if (!is_string($tenantId) || $tenantId === '') {
            throw new BadRequest(
                'A CRM (internal) source must belong to a tenant. ' .
                'Assign a team so the tenant can be derived.'
            );
        }

        $where = [
            'kind' => TrackingSource::KIND_CRM,
            'tenantId' => $tenantId,
            'deleted' => false,
        ];

        if ($entity->hasId()) {
            $where['id!='] = $entity->getId();
        }

        $duplicate = $this->entityManager
            ->getRDBRepository(TrackingSource::ENTITY_TYPE)
            ->where($where)
            ->findOne();

        if ($duplicate) {
            throw new BadRequest(
                'Only one CRM (internal) source can exist per tenant — internal CRM events ' .
                'are recorded against a single source to avoid duplicates. ' .
                'Edit the existing one ("' . $duplicate->get('name') . '") instead.'
            );
        }
    }
}

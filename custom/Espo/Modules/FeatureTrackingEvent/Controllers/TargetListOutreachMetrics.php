<?php
/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2026 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

declare(strict_types=1);

namespace Espo\Modules\FeatureTrackingEvent\Controllers;

use Espo\Core\Acl;
use Espo\Core\Api\Request;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Entities\User;
use Espo\Modules\FeatureTrackingEvent\Services\TargetListOutreachMetricsService;
use Espo\ORM\EntityManager;
use stdClass;

/**
 * Read-only outbound flow metrics for a TargetList (Resources/routes.json):
 *
 *   GET /TargetList/{id}/outreachMetrics
 *
 * Returns the per-stage funnel and won/lost outcomes computed from the
 * TrackingEvent ledger (see TargetListOutreachMetricsService). Consumed by
 * the "Outreach Flow" panel on the TargetList detail view.
 *
 * ACL: read access to the TargetList is required here; the service then
 * applies strict Opportunity access control for the CURRENT USER, so the
 * aggregates only cover funnel motion of opportunities the user can read
 * (team/tenant isolation). No raw TrackingEvent rows are exposed (agents
 * typically lack TrackingEvent read access).
 */
class TargetListOutreachMetrics
{
    public function __construct(
        private EntityManager $entityManager,
        private Acl $acl,
        private User $user,
        private TargetListOutreachMetricsService $service,
    ) {}

    /**
     * @throws BadRequest
     * @throws NotFound
     * @throws Forbidden
     */
    public function getActionMetrics(Request $request): stdClass
    {
        $id = $request->getRouteParam('id');

        if (!is_string($id) || $id === '') {
            throw new BadRequest('No id.');
        }

        $targetList = $this->entityManager->getEntityById('TargetList', $id);

        if ($targetList === null) {
            throw new NotFound('Target List not found.');
        }

        if (!$this->acl->checkEntityRead($targetList)) {
            throw new Forbidden();
        }

        return $this->service->build($id, $this->user);
    }
}

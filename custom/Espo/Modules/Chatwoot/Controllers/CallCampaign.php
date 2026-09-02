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

namespace Espo\Modules\Chatwoot\Controllers;

use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Controllers\Record;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Modules\Chatwoot\Services\CallCampaignService;
use stdClass;

/**
 * Controller for the Call Campaign entity.
 *
 * Extends Record for standard CRUD and adds launch/abort lifecycle actions.
 */
class CallCampaign extends Record implements \Espo\Core\Di\EntityManagerAware
{
    use \Espo\Core\Di\EntityManagerSetter;

    /**
     * Launch a call campaign (resolve audience, enroll, seed the Chatwoot
     * dialer campaign, schedule lead-push chunks).
     *
     * POST /api/v1/CallCampaign/:id/launch
     */
    public function postActionLaunch(Request $request, Response $response): stdClass
    {
        $id = $request->getRouteParam('id');

        if (!$id) {
            throw new BadRequest('Missing campaign ID.');
        }

        $campaign = $this->requireEditableCampaign($id);
        $this->assertOpportunityCreateAccess($campaign);

        $campaign = $this->getCallCampaignService()->launch($id);

        return (object) $campaign->getValueMap();
    }

    /**
     * Abort a running call campaign.
     *
     * POST /api/v1/CallCampaign/:id/abort
     */
    public function postActionAbort(Request $request, Response $response): stdClass
    {
        $id = $request->getRouteParam('id');

        if (!$id) {
            throw new BadRequest('Missing campaign ID.');
        }

        $this->requireEditableCampaign($id);

        $campaign = $this->getCallCampaignService()->abort($id);

        return (object) $campaign->getValueMap();
    }

    private function getCallCampaignService(): CallCampaignService
    {
        return $this->injectableFactory->create(CallCampaignService::class);
    }

    private function requireEditableCampaign(string $id): \Espo\ORM\Entity
    {
        $campaign = $this->entityManager->getEntityById('CallCampaign', $id);

        if (!$campaign) {
            throw new NotFound("Call campaign {$id} not found.");
        }

        if (!$this->acl->check($campaign, 'edit')) {
            throw new Forbidden('No edit access to the call campaign.');
        }

        return $campaign;
    }

    private function assertOpportunityCreateAccess(\Espo\ORM\Entity $campaign): void
    {
        if (!$campaign->get('createOpportunity')) {
            return;
        }

        if (!$this->acl->checkScope('Opportunity', 'create')) {
            throw new Forbidden('No create access to Opportunities.');
        }

        foreach ([
            ['Funnel', 'funnelId'],
            ['OpportunityStage', 'opportunityStageId'],
            ['User', 'opportunityAssignedUserId'],
        ] as [$entityType, $attribute]) {
            $linkedId = $campaign->get($attribute);
            $linked = $linkedId
                ? $this->entityManager->getEntityById($entityType, $linkedId)
                : null;

            if (!$linked || !$this->acl->check($linked, 'read')) {
                throw new Forbidden("No read access to the configured {$entityType}.");
            }
        }
    }
}

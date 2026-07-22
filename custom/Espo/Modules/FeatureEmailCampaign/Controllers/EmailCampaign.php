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

namespace Espo\Modules\FeatureEmailCampaign\Controllers;

use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Controllers\Record;
use Espo\Core\Di;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Modules\FeatureEmailCampaign\Services\EmailCampaignService;
use stdClass;

class EmailCampaign extends Record implements Di\EntityManagerAware
{
    use Di\EntityManagerSetter;

    public function postActionSend(Request $request, Response $response): stdClass
    {
        $id = $request->getRouteParam('id');

        if (!$id) {
            throw new BadRequest('Missing campaign ID.');
        }

        $campaign = $this->requireEditableCampaign($id);
        $this->assertOpportunityCreateAccess($campaign);
        $campaign = $this->getService()->launch($id);

        return (object) $campaign->getValueMap();
    }

    public function postActionAbort(Request $request, Response $response): stdClass
    {
        $id = $request->getRouteParam('id');

        if (!$id) {
            throw new BadRequest('Missing campaign ID.');
        }

        $this->requireEditableCampaign($id);
        $campaign = $this->getService()->abort($id);

        return (object) $campaign->getValueMap();
    }

    public function postActionStopEnrollment(Request $request, Response $response): stdClass
    {
        $id = $request->getRouteParam('id');

        if (!$id) {
            throw new BadRequest('Missing campaign ID.');
        }

        $this->requireEditableCampaign($id);
        $campaign = $this->getService()->stopEnrollment($id);

        return (object) $campaign->getValueMap();
    }

    private function getService(): EmailCampaignService
    {
        return $this->injectableFactory->create(EmailCampaignService::class);
    }

    private function requireEditableCampaign(string $id): \Espo\ORM\Entity
    {
        $campaign = $this->entityManager->getEntityById('EmailCampaign', $id);

        if (!$campaign) {
            throw new NotFound("Campaign {$id} not found.");
        }

        if (!$this->acl->check($campaign, 'edit')) {
            throw new Forbidden('No edit access to the campaign.');
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

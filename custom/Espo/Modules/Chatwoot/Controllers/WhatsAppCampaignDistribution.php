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

namespace Espo\Modules\Chatwoot\Controllers;

use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Controllers\Record;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Modules\Chatwoot\Services\WhatsAppCampaignDistributionService;
use stdClass;

/**
 * Controller for WhatsApp Campaign Distribution entity.
 *
 * Extends Record for standard CRUD and adds activate and stop lifecycle actions.
 */
class WhatsAppCampaignDistribution extends Record
{
    /**
     * Activate a campaign distribution.
     *
     * POST /api/v1/WhatsAppCampaignDistribution/:id/activate
     */
    public function postActionActivate(Request $request, Response $response): stdClass
    {
        $id = $request->getRouteParam('id');

        if (!$id) {
            throw new BadRequest('Missing distribution ID.');
        }

        $distribution = $this->requireEditableDistribution($id);
        $this->assertEntryCampaignAccess($id);
        $distribution = $this->getDistributionService()->activate($id);

        return (object) $distribution->getValueMap();
    }

    /**
     * Stop an active campaign distribution.
     *
     * POST /api/v1/WhatsAppCampaignDistribution/:id/stop
     */
    public function postActionStop(Request $request, Response $response): stdClass
    {
        $id = $request->getRouteParam('id');

        if (!$id) {
            throw new BadRequest('Missing distribution ID.');
        }

        $this->requireEditableDistribution($id);
        $distribution = $this->getDistributionService()->stop($id);

        return (object) $distribution->getValueMap();
    }

    private function getDistributionService(): WhatsAppCampaignDistributionService
    {
        return $this->injectableFactory->create(WhatsAppCampaignDistributionService::class);
    }

    private function requireEditableDistribution(string $id): \Espo\ORM\Entity
    {
        $distribution = $this->entityManager
            ->getEntityById('WhatsAppCampaignDistribution', $id);

        if (!$distribution) {
            throw new NotFound("Distribution {$id} not found.");
        }

        if (!$this->acl->check($distribution, 'edit')) {
            throw new Forbidden('No edit access to the campaign distribution.');
        }

        return $distribution;
    }

    private function assertEntryCampaignAccess(string $distributionId): void
    {
        $entries = $this->entityManager
            ->getRDBRepository('WhatsAppCampaignDistributionEntry')
            ->where(['distributionId' => $distributionId])
            ->find();

        foreach ($entries as $entry) {
            $campaign = $this->entityManager
                ->getEntityById('WhatsAppCampaign', $entry->get('campaignId'));

            if (!$campaign) {
                continue;
            }

            if (!$this->acl->check($campaign, 'edit')) {
                throw new Forbidden('No edit access to an entry campaign.');
            }

            if (
                $campaign->get('createOpportunity')
                && !$this->acl->checkScope('Opportunity', 'create')
            ) {
                throw new Forbidden('No create access to Opportunities.');
            }

            if ($campaign->get('createOpportunity')) {
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
                        throw new Forbidden("No read access to an entry campaign's {$entityType}.");
                    }
                }
            }
        }
    }
}

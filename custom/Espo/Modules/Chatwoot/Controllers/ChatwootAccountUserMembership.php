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
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Error;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Modules\Chatwoot\Services\ChatwootAccountUserMembershipService;
use stdClass;

/**
 * Controller for ChatwootAccountUserMembership entity.
 *
 * Provides custom actions for enabling/disabling AI agent profiles
 * on membership entities (Phase 7).
 */
class ChatwootAccountUserMembership extends \Espo\Core\Templates\Controllers\Base
{
    /**
     * POST ChatwootAccountUserMembership/:id/enableAiProfile
     *
     * Creates or re-enables an AI agent profile for this membership.
     *
     * @throws BadRequest
     * @throws Error
     * @throws Forbidden
     * @throws NotFound
     */
    public function postActionEnableAiProfile(Request $request, Response $response): stdClass
    {
        $id = $request->getRouteParam('id');

        if (!$id) {
            throw new BadRequest("ID is required.");
        }

        $membership = $this->getEntityManager()->getEntityById('ChatwootAccountUserMembership', $id);

        if (!$membership) {
            throw new NotFound("Membership not found.");
        }

        // Validate: must have linked user and account
        if (!$membership->get('chatwootUserId') || !$membership->get('chatwootAccountId')) {
            throw new BadRequest("Membership must have both a Chat Account and Chat User linked.");
        }

        // If agent already linked, check if AI is already enabled
        $agentId = $membership->get('chatwootAgentId');
        if ($agentId) {
            $agent = $this->getEntityManager()->getEntityById('ChatwootAgent', $agentId);
            if ($agent && $agent->get('isAI')) {
                throw new BadRequest("AI profile is already enabled on the linked agent.");
            }
        }

        $service = $this->getMembershipService();
        $updatedMembership = $service->enableAiProfile($membership);

        return $updatedMembership->getValueMap();
    }

    /**
     * POST ChatwootAccountUserMembership/:id/disableAiProfile
     *
     * Disables AI capabilities on the linked agent profile.
     * The agent entity and link are preserved (Decision #10).
     *
     * @throws BadRequest
     * @throws Error
     * @throws Forbidden
     * @throws NotFound
     */
    public function postActionDisableAiProfile(Request $request, Response $response): stdClass
    {
        $id = $request->getRouteParam('id');

        if (!$id) {
            throw new BadRequest("ID is required.");
        }

        $membership = $this->getEntityManager()->getEntityById('ChatwootAccountUserMembership', $id);

        if (!$membership) {
            throw new NotFound("Membership not found.");
        }

        // Validate: must have an agent linked
        $agentId = $membership->get('chatwootAgentId');
        if (!$agentId) {
            throw new BadRequest("No agent profile is linked to this membership.");
        }

        // Validate: agent must have isAI = true
        $agent = $this->getEntityManager()->getEntityById('ChatwootAgent', $agentId);
        if (!$agent) {
            throw new BadRequest("Linked agent profile not found.");
        }

        if (!$agent->get('isAI')) {
            throw new BadRequest("AI profile is already disabled on the linked agent.");
        }

        $service = $this->getMembershipService();
        $updatedMembership = $service->disableAiProfile($membership);

        return $updatedMembership->getValueMap();
    }

    private function getMembershipService(): ChatwootAccountUserMembershipService
    {
        return $this->injectableFactory->create(ChatwootAccountUserMembershipService::class);
    }
}

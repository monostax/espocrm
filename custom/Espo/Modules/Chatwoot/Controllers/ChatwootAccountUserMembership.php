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
use Espo\Core\Di;
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
 * on membership entities.
 */
class ChatwootAccountUserMembership extends \Espo\Core\Templates\Controllers\Base implements Di\EntityManagerAware
{
    use Di\EntityManagerSetter;

    /**
     * POST ChatwootAccountUserMembership/:id/enableAiProfile
     *
     * Enables the AI agent profile on this membership.
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

        $membership = $this->entityManager->getEntityById('ChatwootAccountUserMembership', $id);

        if (!$membership) {
            throw new NotFound("Membership not found.");
        }

        // Validate: must have linked user and account
        if (!$membership->get('chatwootUserId') || !$membership->get('chatwootAccountId')) {
            throw new BadRequest("Membership must have both a Chat Account and Chat User linked.");
        }

        // Check if AI is already enabled directly on the membership
        if ($membership->get('isAI')) {
            throw new BadRequest("AI profile is already enabled on this membership.");
        }

        $service = $this->getMembershipService();
        $updatedMembership = $service->enableAiProfile($membership);

        return $updatedMembership->getValueMap();
    }

    /**
     * POST ChatwootAccountUserMembership/:id/disableAiProfile
     *
     * Disables AI capabilities on this membership.
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

        $membership = $this->entityManager->getEntityById('ChatwootAccountUserMembership', $id);

        if (!$membership) {
            throw new NotFound("Membership not found.");
        }

        // Check if AI is already disabled directly on the membership
        if (!$membership->get('isAI')) {
            throw new BadRequest("AI profile is already disabled on this membership.");
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

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

namespace Espo\Modules\FeatureMetaWhatsAppBusiness\Services;

use Espo\Core\Acl;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Shared OAuthAccount validation logic for WhatsApp Business virtual entities.
 *
 * Used by WhatsAppBusinessAccount, WhatsAppBusinessAccountPhoneNumber,
 * and WhatsAppBusinessAccountMessageTemplate services to validate
 * OAuthAccount access.
 */
class WhatsAppOAuthHelper
{
    public function __construct(
        private EntityManager $entityManager,
        private Acl $acl,
    ) {}

    /**
     * Validate that the current user has read access to an OAuthAccount
     * and that it is active with a valid provider.
     *
     * @param string $oAuthAccountId
     * @return Entity The OAuthAccount entity
     * @throws NotFound
     * @throws Forbidden
     * @throws BadRequest
     */
    public function validateOAuthAccountAccess(string $oAuthAccountId): Entity
    {
        $oAuthAccount = $this->entityManager->getEntityById('OAuthAccount', $oAuthAccountId);

        if (!$oAuthAccount) {
            throw new NotFound("OAuth Account not found.");
        }

        if (!$this->acl->check($oAuthAccount, 'read')) {
            throw new Forbidden("You don't have access to use this OAuth Account.");
        }

        // Verify the provider is active.
        $providerId = $oAuthAccount->get('providerId');

        if (!$providerId) {
            throw new BadRequest("OAuth Account has no provider assigned.");
        }

        $provider = $this->entityManager->getEntityById('OAuthProvider', $providerId);

        if (!$provider) {
            throw new BadRequest("OAuth Provider not found.");
        }

        if (!$provider->get('isActive')) {
            throw new BadRequest("OAuth Provider is not active.");
        }

        return $oAuthAccount;
    }

    /**
     * Get all active OAuthAccounts accessible to the current user.
     *
     * @return Entity[]
     */
    public function getAccessibleOAuthAccounts(): array
    {
        $oAuthAccounts = $this->entityManager
            ->getRDBRepository('OAuthAccount')
            ->find();

        $accessible = [];

        foreach ($oAuthAccounts as $oAuthAccount) {
            if (!$this->acl->check($oAuthAccount, 'read')) {
                continue;
            }

            // Only include accounts that have an access token.
            if (!$oAuthAccount->get('accessToken')) {
                continue;
            }

            // Only include accounts whose provider is active.
            $providerId = $oAuthAccount->get('providerId');

            if (!$providerId) {
                continue;
            }

            $provider = $this->entityManager->getEntityById('OAuthProvider', $providerId);

            if (!$provider || !$provider->get('isActive')) {
                continue;
            }

            $accessible[] = $oAuthAccount;
        }

        return $accessible;
    }
}

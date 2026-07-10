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

namespace Espo\Modules\Chatwoot\Classes\Acl\WhatsAppCampaignDistributionEntry;

use Espo\Core\Acl\AccessEntityCREDSChecker;
use Espo\Core\Acl\ScopeData;
use Espo\Core\AclManager;
use Espo\Entities\User;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Entry rows follow the parent WhatsAppCampaignDistribution ACL.
 *
 * Entries carry no teams of their own (they are weight assignments inside a
 * distribution), so scope/team-level checks are not reliable; access is
 * granted whenever the user can act on the parent distribution. Keeps the
 * entries relationship panel visible and editable in the frontend.
 */
class AccessChecker implements AccessEntityCREDSChecker
{
    public function __construct(
        private AclManager $aclManager,
        private EntityManager $entityManager,
    ) {}

    public function check(User $user, ScopeData $data): bool
    {
        return $this->aclManager->checkScope($user, 'WhatsAppCampaignDistribution');
    }

    public function checkCreate(User $user, ScopeData $data): bool
    {
        return $this->aclManager->checkScope($user, 'WhatsAppCampaignDistribution', 'create')
            || $this->aclManager->checkScope($user, 'WhatsAppCampaignDistribution', 'edit');
    }

    public function checkRead(User $user, ScopeData $data): bool
    {
        return $this->aclManager->checkScope($user, 'WhatsAppCampaignDistribution', 'read');
    }

    public function checkEdit(User $user, ScopeData $data): bool
    {
        return $this->aclManager->checkScope($user, 'WhatsAppCampaignDistribution', 'edit');
    }

    public function checkDelete(User $user, ScopeData $data): bool
    {
        return $this->aclManager->checkScope($user, 'WhatsAppCampaignDistribution', 'edit')
            || $this->aclManager->checkScope($user, 'WhatsAppCampaignDistribution', 'delete');
    }

    public function checkStream(User $user, ScopeData $data): bool
    {
        return $this->checkRead($user, $data);
    }

    public function checkEntityCreate(User $user, Entity $entity, ScopeData $data): bool
    {
        return $this->canAccessParent($user, $entity, 'edit')
            || $this->canAccessParent($user, $entity, 'create');
    }

    public function checkEntityRead(User $user, Entity $entity, ScopeData $data): bool
    {
        return $this->canAccessParent($user, $entity, 'read');
    }

    public function checkEntityEdit(User $user, Entity $entity, ScopeData $data): bool
    {
        return $this->canAccessParent($user, $entity, 'edit');
    }

    public function checkEntityDelete(User $user, Entity $entity, ScopeData $data): bool
    {
        return $this->canAccessParent($user, $entity, 'edit')
            || $this->canAccessParent($user, $entity, 'delete');
    }

    public function checkEntityStream(User $user, Entity $entity, ScopeData $data): bool
    {
        return $this->checkEntityRead($user, $entity, $data);
    }

    private function canAccessParent(User $user, Entity $entity, string $action): bool
    {
        $distributionId = $entity->get('distributionId');

        if (!$distributionId) {
            return $this->aclManager->checkScope($user, 'WhatsAppCampaignDistribution', $action);
        }

        $distribution = $this->entityManager
            ->getEntityById('WhatsAppCampaignDistribution', $distributionId);

        if (!$distribution) {
            return false;
        }

        return $this->aclManager->checkEntity($user, $distribution, $action);
    }
}

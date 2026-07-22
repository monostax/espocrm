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

namespace Espo\Modules\FeatureEmailCampaign\Classes\Acl\EmailCampaignContact;

use Espo\Core\Acl\AccessEntityCREDSChecker;
use Espo\Core\Acl\ScopeData;
use Espo\Core\AclManager;
use Espo\Entities\User;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

class AccessChecker implements AccessEntityCREDSChecker
{
    public function __construct(
        private AclManager $aclManager,
        private EntityManager $entityManager,
    ) {}

    public function check(User $user, ScopeData $data): bool
    {
        return $this->aclManager->checkScope($user, 'EmailCampaign');
    }

    public function checkCreate(User $user, ScopeData $data): bool
    {
        return $this->aclManager->checkScope($user, 'EmailCampaign', 'create')
            || $this->aclManager->checkScope($user, 'EmailCampaign', 'edit');
    }

    public function checkRead(User $user, ScopeData $data): bool
    {
        return $this->aclManager->checkScope($user, 'EmailCampaign', 'read');
    }

    public function checkEdit(User $user, ScopeData $data): bool
    {
        return $this->aclManager->checkScope($user, 'EmailCampaign', 'edit');
    }

    public function checkDelete(User $user, ScopeData $data): bool
    {
        return $this->aclManager->checkScope($user, 'EmailCampaign', 'edit')
            || $this->aclManager->checkScope($user, 'EmailCampaign', 'delete');
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
        $campaignId = $entity->get('emailCampaignId');

        if (!$campaignId) {
            return $this->aclManager->checkScope($user, 'EmailCampaign', $action);
        }

        $campaign = $this->entityManager->getEntityById('EmailCampaign', $campaignId);

        if (!$campaign) {
            return false;
        }

        return $this->aclManager->checkEntity($user, $campaign, $action);
    }
}

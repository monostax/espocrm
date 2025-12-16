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

namespace Espo\Modules\Global\Classes\Acl\OpportunityStage;

use Espo\Entities\User;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\Core\Acl\AccessEntityCREDSChecker;
use Espo\Core\Acl\DefaultAccessChecker;
use Espo\Core\Acl\ScopeData;
use Espo\Core\Acl\Traits\DefaultAccessCheckerDependency;

/**
 * Custom ACL Access Checker for OpportunityStage.
 *
 * OpportunityStage access is inherited from its Funnel.
 * A user can access an OpportunityStage if they belong to the Funnel's team.
 *
 * @implements AccessEntityCREDSChecker<Entity>
 */
class AccessChecker implements AccessEntityCREDSChecker
{
    use DefaultAccessCheckerDependency;

    public function __construct(
        DefaultAccessChecker $defaultAccessChecker,
        private EntityManager $entityManager,
    ) {
        $this->defaultAccessChecker = $defaultAccessChecker;
    }

    /**
     * Check if user belongs to the stage's funnel's team.
     */
    private function userBelongsToFunnelTeam(User $user, Entity $entity): bool
    {
        $funnelId = $entity->get('funnelId');

        if (!$funnelId) {
            return false;
        }

        // Get the funnel to check its team
        $funnel = $this->entityManager->getEntityById('Funnel', $funnelId);

        if (!$funnel) {
            return false;
        }

        $funnelTeamId = $funnel->get('teamId');

        if (!$funnelTeamId) {
            return false;
        }

        $userTeamIds = $user->getTeamIdList();

        return in_array($funnelTeamId, $userTeamIds);
    }

    public function checkEntityRead(User $user, Entity $entity, ScopeData $data): bool
    {
        // Admin bypass
        if ($user->isAdmin()) {
            return true;
        }

        // Check if user belongs to funnel's team
        return $this->userBelongsToFunnelTeam($user, $entity);
    }

    public function checkEntityEdit(User $user, Entity $entity, ScopeData $data): bool
    {
        // Admin bypass
        if ($user->isAdmin()) {
            return true;
        }

        // Check base edit permission
        if (!$this->defaultAccessChecker->checkEdit($user, $data)) {
            return false;
        }

        // Check if user belongs to funnel's team
        return $this->userBelongsToFunnelTeam($user, $entity);
    }

    public function checkEntityDelete(User $user, Entity $entity, ScopeData $data): bool
    {
        // Admin bypass
        if ($user->isAdmin()) {
            return true;
        }

        // Check base delete permission
        if (!$this->defaultAccessChecker->checkDelete($user, $data)) {
            return false;
        }

        // Check if user belongs to funnel's team
        return $this->userBelongsToFunnelTeam($user, $entity);
    }

    public function checkEntityStream(User $user, Entity $entity, ScopeData $data): bool
    {
        return $this->checkEntityRead($user, $entity, $data);
    }
}

<?php

declare(strict_types=1);

namespace Espo\Modules\Global\Classes\Acl\CrmTag;

use Espo\Core\Acl\OwnershipTeamChecker;
use Espo\Entities\User;
use Espo\Modules\Global\Tools\Acl\TeamsAccess;
use Espo\ORM\Entity;

class OwnershipChecker implements OwnershipTeamChecker
{
    public function __construct(private TeamsAccess $teams) {}

    public function checkOwn(User $user, Entity $entity): bool
    {
        return $entity->get('visibility') === 'personal' && $entity->get('ownerUserId') === $user->getId();
    }

    public function checkTeam(User $user, Entity $entity): bool
    {
        return $entity->get('visibility') !== 'personal' && $this->teams->userSharesTeam($user, $entity);
    }
}

<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Enrichment;

use Espo\Core\AclManager;
use Espo\Core\Exceptions\Forbidden;
use Espo\Entities\User;
use Espo\Modules\Global\Tools\Tenant\UserTenantResolver;
use Espo\ORM\EntityManager;

/** Scheduled jobs must reauthorize the original purchaser, never the daemon's system user. */
class ActorAccess
{
    public function __construct(private EntityManager $entityManager, private AclManager $aclManager, private UserTenantResolver $tenants) {}
    public function forActor(string $actorId): Access
    {
        $user = $this->entityManager->getEntityById('User', $actorId);
        if (!$user instanceof User || $user->get('deleted') || !$user->get('isActive') || $user->isPortal()) {
            throw new Forbidden('Enrichment purchaser is unavailable.');
        }
        return new Access($user, $this->aclManager->createUserAcl($user), $this->tenants, $this->entityManager);
    }
}

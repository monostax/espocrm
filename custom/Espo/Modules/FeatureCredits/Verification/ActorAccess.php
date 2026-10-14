<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCredits\Verification;

use Espo\Core\AclManager;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Utils\Crypt;
use Espo\Entities\User;
use Espo\Modules\Global\Tools\Tenant\UserTenantResolver;
use Espo\ORM\EntityManager;

/**
 * Reauthorizes the original actor during background job dispatch and completion (D10, D24).
 */
class ActorAccess
{
    public function __construct(
        private EntityManager $entityManager,
        private AclManager $aclManager,
        private UserTenantResolver $tenants,
        private Crypt $crypt,
    ) {}

    public function forActor(string $actorId): Access
    {
        $user = $this->entityManager->getEntityById('User', $actorId);
        if (!$user instanceof User || $user->get('deleted') || !$user->get('isActive') || $user->isPortal()) {
            throw new Forbidden('Phone verification requester is unavailable.');
        }
        return new Access(
            $user,
            $this->aclManager->createUserAcl($user),
            $this->tenants,
            $this->entityManager,
            $this->crypt,
        );
    }
}

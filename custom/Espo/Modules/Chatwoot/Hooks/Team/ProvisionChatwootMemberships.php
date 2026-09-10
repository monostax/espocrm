<?php

namespace Espo\Modules\Chatwoot\Hooks\Team;

use Espo\Modules\Chatwoot\Hooks\User\ProvisionChatwootMemberships as UserProvisioning;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/** Team-side relation writes do not fire User.afterRelate in Espo. */
class ProvisionChatwootMemberships
{
    public static int $order = 90;

    public function __construct(
        private EntityManager $entityManager,
        private UserProvisioning $provisioning,
    ) {}

    public function afterRelate(Entity $entity, array $options, array $relationParams): void
    {
        if (($relationParams['relationName'] ?? null) !== 'users' || empty($relationParams['foreignId'])) {
            return;
        }

        $user = $this->entityManager->getEntityById('User', $relationParams['foreignId']);
        if ($user) {
            $this->provisioning->afterRelate($user, $options, ['relationName' => 'teams']);
        }
    }
}

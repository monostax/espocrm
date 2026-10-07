<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Services;

use Espo\Core\Exceptions\Forbidden;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

class ManagedIdentityPolicy
{
    public function __construct(private EntityManager $entityManager) {}

    public static function isReservedEmail(?string $email): bool
    {
        $domain = rtrim(strtolower(trim(substr(strrchr($email ?? '', '@') ?: '', 1))), '.');
        return $domain === 'monostax-ext.com' || str_ends_with($domain, '.monostax-ext.com');
    }

    public function forUser(string $userId): ?Entity
    {
        return $this->entityManager->getRDBRepository('ChatwootMachineIdentity')
            ->where(['chatwootUserId' => $userId])->findOne();
    }

    public function assertMembership(Entity $membership): void
    {
        $oldIdentity = $membership->isNew() ? null : $this->entityManager->getRDBRepository('ChatwootMachineIdentity')
            ->where(['membershipId' => $membership->getId()])->findOne();
        $identity = $oldIdentity ?? ($membership->get('chatwootUserId') ? $this->forUser($membership->get('chatwootUserId')) : null);
        if (!$identity) return;
        if ($identity->get('status') === 'retired' || $membership->getId() !== $identity->get('membershipId') ||
            $membership->get('chatwootAccountId') !== $identity->get('accountId') ||
            $membership->get('chatwootUserId') !== $identity->get('chatwootUserId') ||
            $membership->get('role') !== 'agent' || $membership->get('globalAdmin') ||
            $membership->get('email') !== $identity->get('email')) {
            throw new Forbidden('Managed AI identity ownership cannot be changed.');
        }
    }

    public function forCrmUser(string $userId): ?Entity
    {
        return $this->entityManager->getRDBRepository('ChatwootMachineIdentity')
            ->where(['crmUserId' => $userId])->findOne();
    }

    public function permitsCrmRelation(Entity $entity, string $link, string $foreignId): bool
    {
        $type = $entity->getEntityType();
        if ($type === 'User' && in_array($link, ['teams', 'tenants', 'roles'], true)) {
            $identity = $this->forCrmUser($entity->getId());
            $targetType = ['teams' => 'Team', 'tenants' => 'Tenant', 'roles' => 'Role'][$link];
            $targetId = $foreignId;
        } elseif (in_array($type, ['Team', 'Tenant', 'Role'], true) && $link === 'users') {
            $identity = $this->forCrmUser($foreignId);
            $targetType = $type;
            $targetId = $entity->getId();
        } else {
            return true;
        }
        if (!$identity) return true;
        if ($targetType === 'Tenant') return $targetId === $identity->get('tenantId');
        if ($targetType !== 'Team') return false;
        $tenant = $this->entityManager->getEntityById('Tenant', $identity->get('tenantId'));
        return $targetId === $tenant?->get('baseUserTeamId');
    }
}

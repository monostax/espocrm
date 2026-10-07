<?php

namespace Espo\Modules\Chatwoot\Hooks\User;

use Espo\Core\Exceptions\Forbidden;
use Espo\Entities\User;
use Espo\Modules\Chatwoot\Services\ManagedIdentityPolicy;
use Espo\ORM\Entity;

class RejectManagedEmail
{
    public static int $order = 0;

    public function __construct(private ManagedIdentityPolicy $policy) {}

    public function beforeSave(Entity $entity, array $options): void
    {
        // Only the internal provisioner may create a non-interactive reserved-namespace user.
        if ($entity->isNew() && !empty($options['managedIdentityProvisioning']) && $entity->get('type') === User::TYPE_API) return;
        $identity = $entity->getId() ? $this->policy->forCrmUser($entity->getId()) : null;
        if ($identity) {
            if ($entity->get('type') !== User::TYPE_API || $entity->get('userName') !== $identity->get('email') ||
                $entity->get('emailAddress') !== $identity->get('email') ||
                ($identity->get('status') === 'retired' && $entity->get('isActive'))) {
                throw new Forbidden('Managed CRM identity ownership and login type cannot be changed.');
            }
            foreach (['password', 'apiKey', 'secretKey', 'authMethod', 'emailAddressData', 'teamsIds', 'tenantsIds', 'defaultTeamId', 'rolesIds'] as $field) {
                if ($entity->isAttributeChanged($field)) {
                    throw new Forbidden('Managed CRM identity credentials and workspace cannot be changed.');
                }
            }
            return;
        }
        $emails = [$entity->get('emailAddress'), $entity->get('userName')];
        foreach ($entity->get('emailAddressData') ?? [] as $item) {
            $emails[] = is_object($item) ? ($item->emailAddress ?? null) : ($item['emailAddress'] ?? null);
        }
        foreach ($emails as $email) {
            if (ManagedIdentityPolicy::isReservedEmail($email)) {
                throw new Forbidden('The managed identity namespace cannot be used by a CRM login.');
            }
        }
    }

    public function beforeRemove(Entity $entity, array $options): void
    {
        if ($this->policy->forCrmUser($entity->getId())) {
            throw new Forbidden('Remove the AI agent membership to retire its CRM identity.');
        }
    }
}

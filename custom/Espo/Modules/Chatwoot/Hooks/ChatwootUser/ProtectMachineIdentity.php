<?php

namespace Espo\Modules\Chatwoot\Hooks\ChatwootUser;

use Espo\Core\Exceptions\Forbidden;
use Espo\Modules\Chatwoot\Services\ManagedIdentityPolicy;
use Espo\ORM\Entity;

class ProtectMachineIdentity
{
    public static int $order = 0;

    public function __construct(private ManagedIdentityPolicy $policy) {}

    public function beforeSave(Entity $entity, array $options): void
    {
        if (!empty($options['managedIdentityProvisioning'])) return;
        $identity = $entity->getId() ? $this->policy->forUser($entity->getId()) : null;
        if (!$identity) {
            if ($entity->get('assignedUserId') && $this->policy->forCrmUser($entity->get('assignedUserId'))) {
                throw new Forbidden('A managed CRM user cannot be attached to another Chatwoot identity.');
            }
            if (($entity->isNew() || $entity->isAttributeChanged('emailAddress')) &&
                ManagedIdentityPolicy::isReservedEmail($entity->get('emailAddress'))) {
                throw new Forbidden('Managed identities must be created through AI agent provisioning.');
            }
            return;
        }
        if ($entity->get('emailAddress') !== $identity->get('email') ||
            $entity->get('assignedUserId') !== $identity->get('crmUserId') ||
            $entity->get('platformId') !== $identity->get('platformId') ||
            (int) $entity->get('chatwootUserId') !== (int) $identity->get('remoteUserId') ||
            $entity->isAttributeChanged('password') || $entity->isAttributeChanged('userAccessToken')) {
            throw new Forbidden('Managed AI identity credentials and ownership cannot be changed.');
        }
    }

    public function beforeRemove(Entity $entity, array $options): void
    {
        if ($this->policy->forUser($entity->getId())) {
            throw new Forbidden('Remove the AI agent membership to retire its identity.');
        }
    }
}

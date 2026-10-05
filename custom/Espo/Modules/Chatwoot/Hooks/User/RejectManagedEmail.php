<?php

namespace Espo\Modules\Chatwoot\Hooks\User;

use Espo\Core\Exceptions\Forbidden;
use Espo\Modules\Chatwoot\Services\ManagedIdentityPolicy;
use Espo\ORM\Entity;

class RejectManagedEmail
{
    public static int $order = 0;

    public function beforeSave(Entity $entity, array $options): void
    {
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
}

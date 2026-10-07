<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Classes\RecordHooks;

use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Record\Hook\LinkHook;
use Espo\Modules\Chatwoot\Services\ManagedIdentityPolicy;
use Espo\ORM\Entity;

/** Reject API relation writes before they can change a managed principal's ACL. */
class ProtectManagedCrmLinks implements LinkHook
{
    public function __construct(private ManagedIdentityPolicy $policy) {}

    public function process(Entity $entity, string $link, Entity $foreignEntity): void
    {
        if (!$this->policy->permitsCrmRelation($entity, $link, $foreignEntity->getId())) {
            throw new Forbidden('Managed CRM users are restricted to their workspace base team.');
        }
    }
}

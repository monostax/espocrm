<?php

namespace Espo\Modules\Chatwoot\Hooks\ChatwootAccount;

use Espo\Core\Exceptions\Forbidden;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

class ProtectMachineOwnership
{
    public static int $order = 8;

    public function __construct(private EntityManager $entityManager) {}

    public function beforeSave(Entity $entity, array $options): void
    {
        if ($entity->isNew()) return;
        if (!$entity->isAttributeChanged('tenantId') && !$entity->isAttributeChanged('platformId') &&
            !$entity->isAttributeChanged('chatwootAccountId')) return;
        if ($this->entityManager->getRDBRepository('ChatwootMachineIdentity')->where(['accountId' => $entity->getId()])->findOne()) {
            throw new Forbidden('An account with managed AI identities cannot change its workspace or remote ownership.');
        }
    }
}

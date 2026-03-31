<?php

namespace Espo\Modules\FeatureIntegrationMedx\Hooks\FeatureIntegrationMedxCliente;

use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

class SyncContactClienteId
{
    public static int $order = 5;

    public function __construct(
        private EntityManager $entityManager,
    ) {}

    public function afterSave(Entity $entity, array $options): void
    {
        $clienteId = $entity->get('clienteId');
        $contactId = $entity->get('contactId');
        $fetchedContactId = $entity->getFetched('contactId');

        if ($fetchedContactId && $fetchedContactId !== $contactId) {
            $this->syncContactField($fetchedContactId, null);
        }

        if ($contactId) {
            $this->syncContactField($contactId, $clienteId ?: null);
        }
    }

    public function beforeRemove(Entity $entity, array $options): void
    {
        $contactId = $entity->get('contactId');

        if (!$contactId) {
            return;
        }

        $this->syncContactField($contactId, null);
    }

    private function syncContactField(string $contactId, ?string $clienteId): void
    {
        $contact = $this->entityManager->getEntityById('Contact', $contactId);

        if (!$contact) {
            return;
        }

        if ($contact->get('medx_clienteId') === $clienteId) {
            return;
        }

        $contact->set('medx_clienteId', $clienteId);

        $this->entityManager->saveEntity($contact);
    }
}

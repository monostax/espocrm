<?php

namespace Espo\Modules\FeatureIntegrationClinicaNasNuvens\Hooks\FeatureIntegrationClinicaNasNuvensPaciente;

use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

class SyncContactPacienteId
{
    public static int $order = 5;

    public function __construct(
        private EntityManager $entityManager,
    ) {}

    public function afterSave(Entity $entity, array $options): void
    {
        $pacienteId = $entity->get('pacienteId');
        $contactId = $entity->get('contactId');
        $fetchedContactId = $entity->getFetched('contactId');

        if ($fetchedContactId && $fetchedContactId !== $contactId) {
            $this->syncContactField($fetchedContactId, null);
        }

        if ($contactId) {
            $this->syncContactField($contactId, $pacienteId ?: null);
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

    private function syncContactField(string $contactId, ?string $pacienteId): void
    {
        $contact = $this->entityManager->getEntityById('Contact', $contactId);

        if (!$contact) {
            return;
        }

        if ($contact->get('clinicaNasNuvens_pacienteId') === $pacienteId) {
            return;
        }

        $contact->set('clinicaNasNuvens_pacienteId', $pacienteId);

        $this->entityManager->saveEntity($contact);
    }
}

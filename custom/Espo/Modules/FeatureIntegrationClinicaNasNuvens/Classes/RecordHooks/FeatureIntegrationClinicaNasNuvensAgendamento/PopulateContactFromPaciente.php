<?php

namespace Espo\Modules\FeatureIntegrationClinicaNasNuvens\Classes\RecordHooks\FeatureIntegrationClinicaNasNuvensAgendamento;

use Espo\Core\Record\Hook\ReadHook;
use Espo\Core\Record\ReadParams;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

/**
 * Populates the virtual 'contact' link attributes (contactId, contactName)
 * on Agendamento by resolving through the linked Paciente's Contact.
 *
 * Required because EspoCRM foreign fields only resolve one join level,
 * and Agendamento -> Paciente -> Contact is two levels deep.
 */
class PopulateContactFromPaciente implements ReadHook
{
    public function __construct(
        private EntityManager $entityManager,
    ) {}

    public function process(Entity $entity, ReadParams $params): void
    {
        $pacienteId = $entity->get('pacienteId');

        if (!$pacienteId) {
            return;
        }

        $paciente = $this->entityManager
            ->getEntityById('FeatureIntegrationClinicaNasNuvensPaciente', $pacienteId);

        if (!$paciente) {
            return;
        }

        $contactId = $paciente->get('contactId');

        if (!$contactId) {
            return;
        }

        $contact = $this->entityManager->getEntityById('Contact', $contactId);

        $contactName = $contact?->get('name');

        $entity->set('contactId', $contactId);
        $entity->set('contactName', $contactName);
    }
}

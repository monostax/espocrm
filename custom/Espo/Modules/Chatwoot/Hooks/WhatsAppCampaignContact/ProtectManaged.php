<?php

namespace Espo\Modules\Chatwoot\Hooks\WhatsAppCampaignContact;

use Espo\Core\Exceptions\Forbidden;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

class ProtectManaged
{
    public static int $order = -100;

    public function __construct(private EntityManager $em) {}

    public function beforeSave(Entity $entity, array $options): void
    {
        $parent = $this->em->getEntityById('WhatsAppCampaign', (string) $entity->get('whatsAppCampaignId'));
        if (!$parent?->get('managedByChatwoot') && !$entity->get('managedSendKey') && !$entity->getFetched('managedSendKey')) return;
        if (!empty($options['managedCampaign'])) return;
        if (!empty($options['api'])) throw new Forbidden('Managed recipients are read-only.');
        if ($entity->isNew()) throw new Forbidden('Managed audiences are sealed.');
        foreach (['whatsAppCampaignId', 'contactId', 'chatwootAccountId', 'phoneNumber', 'managedSendKey', 'managedPayload', 'managedFingerprint'] as $field) {
            if ($entity->isAttributeChanged($field)) throw new Forbidden('Managed recipient snapshot is immutable.');
        }
    }

    public function beforeRemove(Entity $entity, array $options): void
    {
        if ($entity->get('managedSendKey')) throw new Forbidden('Managed send keys must be retained.');
    }
}

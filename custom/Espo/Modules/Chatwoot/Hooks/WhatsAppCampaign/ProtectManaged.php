<?php

namespace Espo\Modules\Chatwoot\Hooks\WhatsAppCampaign;

use Espo\Core\Exceptions\Forbidden;
use Espo\ORM\Entity;

class ProtectManaged
{
    public static int $order = -100;

    public function beforeSave(Entity $entity, array $options): void
    {
        if (!($entity->get('managedByChatwoot') || $entity->getFetched('managedByChatwoot')) || !empty($options['managedCampaign'])) return;
        // Delivery counters may advance after completion, but content/audience/configuration never change.
        $evidence = ['sentCount', 'deliveredCount', 'readCount', 'repliedCount', 'failedCount', 'modifiedAt', 'modifiedById'];
        foreach (array_keys(get_object_vars($entity->getValueMap())) as $attribute) {
            if (!in_array($attribute, $evidence, true) && $entity->isAttributeChanged($attribute)) {
                throw new Forbidden('Managed campaign is immutable. Create a new preparation in Chat.');
            }
        }
    }

    public function beforeRemove(Entity $entity, array $options): void
    {
        if ($entity->get('managedByChatwoot')) throw new Forbidden('Managed campaign summaries must be retained.');
    }
}

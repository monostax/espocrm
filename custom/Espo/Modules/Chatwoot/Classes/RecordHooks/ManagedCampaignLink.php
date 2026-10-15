<?php

namespace Espo\Modules\Chatwoot\Classes\RecordHooks;

use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Record\Hook\LinkHook;
use Espo\Core\Record\Hook\UnlinkHook;
use Espo\ORM\Entity;

class ManagedCampaignLink implements LinkHook, UnlinkHook
{
    public function process(Entity $entity, string $link, Entity $foreignEntity): void
    {
        foreach ([$entity, $foreignEntity] as $record) {
            if (($record->getEntityType() === 'WhatsAppCampaign' && $record->get('managedByChatwoot')) ||
                ($record->getEntityType() === 'WhatsAppCampaignContact' && $record->get('managedSendKey'))) {
                throw new Forbidden('Managed campaign relationships are immutable.');
            }
        }
    }
}

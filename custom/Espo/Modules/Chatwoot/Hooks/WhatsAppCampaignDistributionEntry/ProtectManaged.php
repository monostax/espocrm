<?php

namespace Espo\Modules\Chatwoot\Hooks\WhatsAppCampaignDistributionEntry;

use Espo\Core\Exceptions\Forbidden;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;

class ProtectManaged
{
    public static int $order = -100;

    public function __construct(private EntityManager $em) {}

    public function beforeSave(Entity $entity, array $options): void
    {
        $campaign = $this->em->getEntityById('WhatsAppCampaign', (string) $entity->get('campaignId'));
        if ($campaign?->get('managedByChatwoot')) throw new Forbidden('Managed campaigns cannot join distributions.');
    }
}

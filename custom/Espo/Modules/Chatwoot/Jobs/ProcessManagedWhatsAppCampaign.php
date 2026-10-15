<?php

namespace Espo\Modules\Chatwoot\Jobs;

use Espo\Core\Job\Job;
use Espo\Core\Job\Job\Data;
use Espo\Modules\Chatwoot\Services\ManagedCampaignPreparation;
use Espo\Modules\Chatwoot\Services\ManagedCampaignExecution;
use Espo\ORM\EntityManager;

class ProcessManagedWhatsAppCampaign implements Job
{
    public function __construct(private EntityManager $em, private ManagedCampaignPreparation $preparation, private ManagedCampaignExecution $execution) {}

    public function run(Data $data): void
    {
        $pdo = $this->em->getPDO();
        $name = 'managed-campaign-work:' . (string) $data->get('campaignId');
        $lock = $pdo->prepare('SELECT GET_LOCK(?, 0)');
        $lock->execute([$name]);
        if ((int) $lock->fetchColumn() !== 1) return;
        try {
            $this->process($data);
        } finally {
            $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
            $release->execute([$name]);
        }
    }

    private function process(Data $data): void
    {
        $campaign = $this->em->getEntityById('WhatsAppCampaign', (string) $data->get('campaignId'));
        if (!$campaign || !$campaign->get('managedByChatwoot') || (int) $campaign->get('managedGeneration') !== (int) $data->get('generation')) return;
        if ($campaign->get('managedState') === 'Preparing') $this->preparation->process($campaign);
        if ($campaign->get('managedState') === 'Sending') $this->execution->process($campaign);
    }
}

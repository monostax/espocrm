<?php

namespace Espo\Modules\Chatwoot\Jobs;

use Espo\Core\Job\JobDataLess;
use Espo\Modules\Chatwoot\Services\ManagedCampaignMaintenance;

class MaintainManagedWhatsAppCampaigns implements JobDataLess
{
    public function __construct(private ManagedCampaignMaintenance $maintenance) {}
    public function run(): void { $this->maintenance->run(); }
}

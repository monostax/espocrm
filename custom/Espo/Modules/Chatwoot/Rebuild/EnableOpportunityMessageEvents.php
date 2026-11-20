<?php

declare(strict_types=1);

namespace Espo\Modules\Chatwoot\Rebuild;

use Espo\Core\Rebuild\RebuildAction;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Config\ConfigWriter;
use Espo\Modules\Chatwoot\Services\OpportunityMessageEvents;
use Espo\Modules\Chatwoot\Services\OpportunityOverdueActivities;
use Espo\Modules\Chatwoot\Services\OpportunityStreamEvents;

class EnableOpportunityMessageEvents implements RebuildAction
{
    public function __construct(private Config $config, private ConfigWriter $configWriter) {}

    public function process(): void
    {
        $changed = false;
        foreach ([OpportunityMessageEvents::STARTED_AT, OpportunityOverdueActivities::STARTED_AT, ...array_values(OpportunityStreamEvents::DISCUSSION_ROLLOUT)] as $key) {
            if (!$this->config->get($key)) {
                $this->configWriter->set($key, gmdate('Y-m-d H:i:s'));
                $changed = true;
            }
        }
        if ($changed) {
            $this->configWriter->save();
        }
    }
}

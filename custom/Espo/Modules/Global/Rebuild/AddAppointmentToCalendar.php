<?php

namespace Espo\Modules\Global\Rebuild;

use Espo\Core\Rebuild\RebuildAction;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Config\ConfigWriter;
use Espo\Core\Utils\Log;

class AddAppointmentToCalendar implements RebuildAction
{
    public function __construct(
        private Config $config,
        private ConfigWriter $configWriter,
        private Log $log
    ) {}

    public function process(): void
    {
        $this->addToEntityList('calendarEntityList', 'Appointment');
        $this->addToEntityList('activitiesEntityList', 'Appointment');
    }

    private function addToEntityList(string $listName, string $entityType): void
    {
        $list = $this->config->get($listName) ?? [];
        
        if (!is_array($list)) {
            $list = [];
        }

        if (in_array($entityType, $list)) {
            return;
        }

        $list[] = $entityType;
        $this->configWriter->set($listName, $list);
        $this->configWriter->save();
        $this->log->info("Global Module: Added {$entityType} to {$listName}.");
    }
}

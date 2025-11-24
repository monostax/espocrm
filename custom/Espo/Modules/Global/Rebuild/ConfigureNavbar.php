<?php
/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 *
 * This software and associated documentation files (the "Software") are
 * the proprietary and confidential information of Monostax.
 *
 * Unauthorized copying, distribution, modification, public display, or use
 * of this Software, in whole or in part, via any medium, is strictly
 * prohibited without the express prior written permission of Monostax.
 *
 * This Software is licensed, not sold. Commercial use of this Software
 * requires a valid license from Monostax.
 *
 * For licensing information, please visit: https://www.monostax.ai
 ************************************************************************/

namespace Espo\Modules\Global\Rebuild;

use Espo\Core\Rebuild\RebuildAction;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Config\ConfigWriter;
use Espo\Core\Utils\Log;

/**
 * Rebuild action to configure the navbar tab list.
 * Adds ClinicaMedica divider at the top of the navbar.
 * Runs automatically during system rebuild.
 */
class ConfigureNavbar implements RebuildAction
{
    public function __construct(
        private Config $config,
        private ConfigWriter $configWriter,
        private Log $log
    ) {}

    public function process(): void
    {
        $this->updateApplicationName();
        
        $tabList = $this->config->get('tabList');
        
        if (!is_array($tabList)) {
            $this->log->warning('Global Module: tabList is not an array, skipping navbar configuration.');
            return;
        }

        $newTabList = $this->addClinicaMedicaSection($tabList);

        if ($newTabList !== $tabList) {
        $this->configWriter->set('tabList', $newTabList);
        $this->configWriter->save();
            $this->log->info('Global Module: Successfully configured navbar - added ClinicaMedica section.');
            }
    }

    private function addClinicaMedicaSection(array $tabList): array
    {
        // Define the complete ClinicaMedica section
        $clinicaMedicaItems = [
            [
            'type' => 'divider',
            'text' => '$ClinicaMedica',
            ],
            'CPaciente',
            'CMedico',
            'CConsultaMedica',
        ];
        
        // Find if ClinicaMedica section exists and remove it (including all items until next divider)
        $newTabList = [];
        $inClinicaMedicaSection = false;
        $foundSection = false;
        
        foreach ($tabList as $item) {
            // Check if we're at the ClinicaMedica divider
            if (is_array($item) && 
                isset($item['type']) && 
                $item['type'] === 'divider' && 
                isset($item['text']) && 
                $item['text'] === '$ClinicaMedica'
            ) {
                $inClinicaMedicaSection = true;
                $foundSection = true;
                continue; // Skip the divider
            }
            
            // If we're in ClinicaMedica section and hit another divider, we're done with this section
            if ($inClinicaMedicaSection && 
                is_array($item) && 
                isset($item['type']) && 
                $item['type'] === 'divider'
            ) {
                $inClinicaMedicaSection = false;
                $newTabList[] = $item;
                continue;
            }
            
            // Skip items that are in the ClinicaMedica section
            if ($inClinicaMedicaSection) {
                continue;
            }
            
            $newTabList[] = $item;
        }
        
        // Prepend the ClinicaMedica section to the beginning
        array_unshift($newTabList, ...$clinicaMedicaItems);
        
        return $newTabList;
    }

    private function updateApplicationName(): void
    {
        $currentApplicationName = $this->config->get('applicationName');
        
        if ($currentApplicationName === 'EspoCRM') {
            $this->configWriter->set('applicationName', 'Monostax CRM');
            $this->configWriter->save();
            $this->log->info('Global Module: Successfully updated application name from EspoCRM to Monostax CRM.');
        }
    }
}


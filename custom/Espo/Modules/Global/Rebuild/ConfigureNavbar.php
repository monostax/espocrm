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
        
        // $tabList = $this->config->get('tabList');
        
        // if (!is_array($tabList)) {
        //     $this->log->warning('Global Module: tabList is not an array, skipping navbar configuration.');
        //     return;
        // }

        // $newTabList = $this->addClinicaMedicaSection($tabList);

        // if ($newTabList !== $tabList) {
        // $this->configWriter->set('tabList', $newTabList);
        // $this->configWriter->save();
        //     $this->log->info('Global Module: Successfully configured navbar - added ClinicaMedica section.');
        //     }
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
            'CAgendamento',
        ];
        
        // Find existing ClinicaMedica section
        $sectionStartIndex = null;
        $sectionEndIndex = null;
        
        foreach ($tabList as $index => $item) {
            // Check if we're at the ClinicaMedica divider
            if (is_array($item) && 
                isset($item['type']) && 
                $item['type'] === 'divider' && 
                isset($item['text']) && 
                $item['text'] === '$ClinicaMedica'
            ) {
                $sectionStartIndex = $index;
                // Find the end of this section (next divider or end of array)
                for ($i = $index + 1; $i < count($tabList); $i++) {
                    if (is_array($tabList[$i]) && 
                        isset($tabList[$i]['type']) && 
                        $tabList[$i]['type'] === 'divider'
                    ) {
                        $sectionEndIndex = $i - 1;
                        break;
                    }
                }
                // If no next divider found, section goes to the end
                if ($sectionEndIndex === null) {
                    $sectionEndIndex = count($tabList) - 1;
                }
                break;
            }
        }
        
        // If ClinicaMedica section exists, replace it in-place
        if ($sectionStartIndex !== null) {
            // Remove old section
            array_splice($tabList, $sectionStartIndex, $sectionEndIndex - $sectionStartIndex + 1);
            // Insert new section at the same position
            array_splice($tabList, $sectionStartIndex, 0, $clinicaMedicaItems);
        } else {
            // Section doesn't exist, add it at the beginning
            array_unshift($tabList, ...$clinicaMedicaItems);
        }
        
        return $tabList;
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


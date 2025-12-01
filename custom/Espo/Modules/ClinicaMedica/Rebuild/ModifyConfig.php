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

namespace Espo\Modules\ClinicaMedica\Rebuild;

use Espo\Core\Rebuild\RebuildAction;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Config\ConfigWriter;
use Espo\Core\Utils\Log;

/**
 * Rebuild action to configure system settings.
 * - Adds Cadastros section with CMedico, CPaciente, and CAgendamento to the navbar.
 * - Ensures CAgendamento is present in calendarEntityList, activitiesEntityList, and historyEntityList.
 * Runs automatically during system rebuild.
 */
class ModifyConfig implements RebuildAction
{
    public function __construct(
        private Config $config,
        private ConfigWriter $configWriter,
        private Log $log
    ) {}

    public function process(): void
    {
        $this->configureNavbar();
        $this->configureEntityLists();
    }

    private function configureNavbar(): void
    {
        $tabList = $this->config->get('tabList');
        
        if (!is_array($tabList)) {
            $this->log->warning('ClinicaMedica Module: tabList is not an array, skipping navbar configuration.');
            return;
        }

        $this->log->debug('ClinicaMedica Module: Current tabList before processing: ' . json_encode($tabList));
        $this->log->debug('ClinicaMedica Module: Current tabList count: ' . count($tabList));

        $newTabList = $this->addCadastrosSection($tabList);

        $this->log->debug('ClinicaMedica Module: New tabList after processing: ' . json_encode($newTabList));
        $this->log->debug('ClinicaMedica Module: New tabList count: ' . count($newTabList));

        if ($newTabList !== $tabList) {
            $this->configWriter->set('tabList', $newTabList);
            $this->configWriter->save();
            $this->log->info('ClinicaMedica Module: Successfully configured navbar - added Cadastros section.');
        } else {
            $this->log->info('ClinicaMedica Module: No changes needed to navbar configuration.');
        }
    }

    private function addCadastrosSection(array $tabList): array
    {
        // Define the complete Cadastros section
        $cadastrosItems = [
            [
                'type' => 'divider',
                'text' => '$Cadastros (Clínica)',
            ],
            'CAgendamento',
            'CPaciente',
            'CMedico',
            'CProcedimento'
        ];
        
        // Find existing Cadastros (Clínica) section
        $sectionStartIndex = null;
        $sectionEndIndex = null;
        
        foreach ($tabList as $index => $item) {
            // Convert object to array for comparison (EspoCRM may store as stdClass)
            $itemArray = is_object($item) ? (array) $item : $item;
            
            // Check if we're at the Cadastros (Clínica) divider
            if (is_array($itemArray) && 
                isset($itemArray['type']) && 
                $itemArray['type'] === 'divider' && 
                isset($itemArray['text']) && 
                $itemArray['text'] === '$Cadastros (Clínica)'
            ) {
                $sectionStartIndex = $index;
                $this->log->debug('ClinicaMedica Module: Found existing Cadastros section at index ' . $index);
                // Find the end of this section (next divider or end of array)
                for ($i = $index + 1; $i < count($tabList); $i++) {
                    $nextItemArray = is_object($tabList[$i]) ? (array) $tabList[$i] : $tabList[$i];
                    if (is_array($nextItemArray) && 
                        isset($nextItemArray['type']) && 
                        $nextItemArray['type'] === 'divider'
                    ) {
                        $sectionEndIndex = $i - 1;
                        break;
                    }
                }
                // If no next divider found, section goes to the end
                if ($sectionEndIndex === null) {
                    $sectionEndIndex = count($tabList) - 1;
                }
                $this->log->debug('ClinicaMedica Module: Cadastros section ends at index ' . $sectionEndIndex);
                break;
            }
        }
        
        // If Cadastros section exists, replace it in-place
        if ($sectionStartIndex !== null) {
            $this->log->info('ClinicaMedica Module: Replacing existing Cadastros section (indices ' . $sectionStartIndex . ' to ' . $sectionEndIndex . ')');
            // Remove old section
            array_splice($tabList, $sectionStartIndex, $sectionEndIndex - $sectionStartIndex + 1);
            // Insert new section at the same position
            array_splice($tabList, $sectionStartIndex, 0, $cadastrosItems);
        } else {
            $this->log->info('ClinicaMedica Module: Adding new Cadastros section at the beginning');
            // Section doesn't exist, add it at the beginning
            array_unshift($tabList, ...$cadastrosItems);
        }
        
        return $tabList;
    }

    private function configureEntityLists(): void
    {
        $changed = false;

        // Configure calendarEntityList
        $calendarEntityList = $this->config->get('calendarEntityList');
        if ($this->upsertEntityToList('calendarEntityList', $calendarEntityList, 'CAgendamento')) {
            $changed = true;
        }

        // Configure activitiesEntityList
        $activitiesEntityList = $this->config->get('activitiesEntityList');
        if ($this->upsertEntityToList('activitiesEntityList', $activitiesEntityList, 'CAgendamento')) {
            $changed = true;
        }

        // Configure historyEntityList
        $historyEntityList = $this->config->get('historyEntityList');
        if ($this->upsertEntityToList('historyEntityList', $historyEntityList, 'CAgendamento')) {
            $changed = true;
        }

        if ($changed) {
            $this->configWriter->save();
            $this->log->info('ClinicaMedica Module: Successfully configured entity lists - added CAgendamento where needed.');
        } else {
            $this->log->info('ClinicaMedica Module: No changes needed to entity lists configuration.');
        }
    }

    private function upsertEntityToList(string $listName, mixed $list, string $entityType): bool
    {
        if (!is_array($list)) {
            $this->log->warning("ClinicaMedica Module: {$listName} is not an array, initializing as empty array.");
            $list = [];
        }

        if (in_array($entityType, $list)) {
            $this->log->debug("ClinicaMedica Module: {$entityType} already exists in {$listName}.");
            return false;
        }

        $list[] = $entityType;
        $this->configWriter->set($listName, $list);
        $this->log->info("ClinicaMedica Module: Added {$entityType} to {$listName}.");
        return true;
    }
}

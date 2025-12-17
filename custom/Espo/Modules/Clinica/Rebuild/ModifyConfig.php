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

namespace Espo\Modules\Clinica\Rebuild;

use Espo\Core\Rebuild\RebuildAction;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Config\ConfigWriter;
use Espo\Core\Utils\Log;

/**
 * Rebuild action to configure system settings.
 * - Adds Cadastros section with CProfissional, CPaciente, and CAgendamento to the navbar.
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
            $this->log->warning('Clinica Module: tabList is not an array, skipping navbar configuration.');
            return;
        }

        $newTabList = $this->upsertToSection($tabList, '$Records', [
            'CAgendamento',
            'CPaciente',
            'CProcedimento',
            'CProfissional',
        ], 'beginning');

        if ($newTabList !== $tabList) {
            $this->configWriter->set('tabList', $newTabList);
            $this->configWriter->save();
            $this->log->info('Clinica Module: Successfully configured navbar.');
        }
    }

    /**
     * Upsert items into a divider section.
     * 
     * @param array $tabList The current tab list
     * @param string $sectionName The divider text to find/create
     * @param array $items Items to upsert (strings for entity names, arrays for complex items)
     * @param string $createPosition Where to create section if missing: 'beginning' or 'end'
     * @return array Modified tab list
     */
    private function upsertToSection(array $tabList, string $sectionName, array $items, string $createPosition = 'beginning'): array
    {
        // Find the divider position and section bounds
        $dividerIndex = null;
        $sectionEndIndex = null;
        
        foreach ($tabList as $index => $item) {
            $itemArray = is_object($item) ? (array) $item : $item;
            
            if (is_array($itemArray) && 
                isset($itemArray['type']) && 
                $itemArray['type'] === 'divider' && 
                isset($itemArray['text']) && 
                $itemArray['text'] === $sectionName
            ) {
                $dividerIndex = $index;
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
                if ($sectionEndIndex === null) {
                    $sectionEndIndex = count($tabList) - 1;
                }
                break;
            }
        }
        
        // If divider doesn't exist, create it
        if ($dividerIndex === null) {
            $divider = [
                'type' => 'divider',
                'text' => $sectionName,
            ];
            if ($createPosition === 'beginning') {
                array_unshift($tabList, $divider);
                $dividerIndex = 0;
            } else {
                $tabList[] = $divider;
                $dividerIndex = count($tabList) - 1;
            }
            $sectionEndIndex = $dividerIndex;
        }
        
        // Collect existing items in the section
        $existingItems = [];
        for ($i = $dividerIndex + 1; $i <= $sectionEndIndex; $i++) {
            $item = $tabList[$i];
            if (is_string($item)) {
                $existingItems[] = $item;
            }
        }
        
        // Upsert: add items that don't exist yet
        $insertPosition = $dividerIndex + 1;
        foreach ($items as $itemToAdd) {
            if (is_string($itemToAdd) && !in_array($itemToAdd, $existingItems)) {
                array_splice($tabList, $insertPosition, 0, [$itemToAdd]);
                $insertPosition++;
            } elseif (is_array($itemToAdd)) {
                // For complex items (like groups), just add them
                array_splice($tabList, $insertPosition, 0, [$itemToAdd]);
                $insertPosition++;
            }
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
            $this->log->info('Clinica Module: Successfully configured entity lists - added CAgendamento where needed.');
        } else {
            $this->log->info('Clinica Module: No changes needed to entity lists configuration.');
        }
    }

    private function upsertEntityToList(string $listName, mixed $list, string $entityType): bool
    {
        if (!is_array($list)) {
            $this->log->warning("Clinica Module: {$listName} is not an array, initializing as empty array.");
            $list = [];
        }

        if (in_array($entityType, $list)) {
            $this->log->debug("Clinica Module: {$entityType} already exists in {$listName}.");
            return false;
        }

        $list[] = $entityType;
        $this->configWriter->set($listName, $list);
        $this->log->info("Clinica Module: Added {$entityType} to {$listName}.");
        return true;
    }
}

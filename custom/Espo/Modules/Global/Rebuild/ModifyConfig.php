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
 * Rebuild action to configure system settings.
 * - Adds/updates the $Records section in the navbar with Account.
 * - Adds/updates the Configuration group in the navbar with Import link.
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
        $this->configureActivitiesEntityList();
    }

    /**
     * Configure activitiesEntityList and historyEntityList.
     * - Add Task to activities
     * - Remove invalid/non-existent entities
     */
    private function configureActivitiesEntityList(): void
    {
        // Valid entity types for activities/history panels
        $validActivityEntities = ['Meeting', 'Call', 'Email', 'Task', 'Appointment'];
        $validHistoryEntities = ['Meeting', 'Call', 'Email', 'Appointment'];

        $modified = false;

        // Configure activitiesEntityList
        $activitiesEntityList = $this->config->get('activitiesEntityList') ?? [];
        
        if (!is_array($activitiesEntityList)) {
            $activitiesEntityList = [];
        }

        // Filter to only valid entities and add Task
        $newActivitiesEntityList = array_values(array_intersect($activitiesEntityList, $validActivityEntities));
        
        // Ensure Task is included
        if (!in_array('Task', $newActivitiesEntityList)) {
            $newActivitiesEntityList[] = 'Task';
        }

        // Ensure Appointment is included
        if (!in_array('Appointment', $newActivitiesEntityList)) {
            $newActivitiesEntityList[] = 'Appointment';
        }

        // Ensure core entities are included
        foreach (['Meeting', 'Call'] as $entity) {
            if (!in_array($entity, $newActivitiesEntityList)) {
                array_unshift($newActivitiesEntityList, $entity);
            }
        }

        if ($newActivitiesEntityList !== $activitiesEntityList) {
            $this->configWriter->set('activitiesEntityList', $newActivitiesEntityList);
            $modified = true;
        }

        // Configure historyEntityList
        $historyEntityList = $this->config->get('historyEntityList') ?? [];
        
        if (!is_array($historyEntityList)) {
            $historyEntityList = [];
        }

        // Filter to only valid entities
        $newHistoryEntityList = array_values(array_intersect($historyEntityList, $validHistoryEntities));

        // Ensure core entities are included
        foreach (['Meeting', 'Call', 'Email'] as $entity) {
            if (!in_array($entity, $newHistoryEntityList)) {
                $newHistoryEntityList[] = $entity;
            }
        }

        // Ensure Appointment is included
        if (!in_array('Appointment', $newHistoryEntityList)) {
            $newHistoryEntityList[] = 'Appointment';
        }

        if ($newHistoryEntityList !== $historyEntityList) {
            $this->configWriter->set('historyEntityList', $newHistoryEntityList);
            $modified = true;
        }

        // Set activitiesCreateButtonMaxCount to 5 to include Task and Appointment icon button
        $currentButtonMaxCount = $this->config->get('activitiesCreateButtonMaxCount');
        if ($currentButtonMaxCount !== 5) {
            $this->configWriter->set('activitiesCreateButtonMaxCount', 5);
            $modified = true;
        }

        if ($modified) {
            $this->configWriter->save();
        }
    }

    private function configureNavbar(): void
    {
        $tabList = $this->config->get('tabList');
        
        if (!is_array($tabList)) {
            return;
        }

        // First: add Calendar and Activities right after Home (before any dividers)
        $newTabList = $this->addCalendarItem($tabList);
        
        // Then: add $CRM section after Calendar/Activities
        $newTabList = $this->addCRMSection($newTabList);
        
        // Other sections
        // $newTabList = $this->upsertToSection($newTabList, '$Records', ['Account'], 'end');
        $newTabList = $this->addActivitiesSection($newTabList);
        // $newTabList = $this->addConfigurationSection($newTabList);
        
        // Filter out null values and re-index
        $newTabList = array_values(array_filter($newTabList, fn($item) => $item !== null));

        if ($newTabList !== $tabList) {
            $this->configWriter->set('tabList', $newTabList);
            $this->configWriter->save();
        }
    }

    /**
     * Upsert items into a divider section.
     * Removes all existing occurrences of the items from the entire tabList first,
     * then adds them to the target section.
     * 
     * @param array $tabList The current tab list
     * @param string $sectionName The divider text to find/create
     * @param array $items Items to upsert (strings for entity names, arrays for complex items)
     * @param string $createPosition Where to create section if missing: 'beginning' or 'end'
     * @return array Modified tab list
     */
    private function upsertToSection(array $tabList, string $sectionName, array $items, string $createPosition = 'beginning'): array
    {
        // First, remove ALL existing occurrences of the string items from the entire tabList
        $stringItems = array_filter($items, 'is_string');
        if (!empty($stringItems)) {
            $tabList = array_values(array_filter($tabList, function($item) use ($stringItems) {
                return !is_string($item) || !in_array($item, $stringItems);
            }));
        }
        
        // Find the divider position
        $dividerIndex = null;
        
        foreach ($tabList as $index => $item) {
            if ($item === null) {
                continue;
            }
            
            $itemArray = is_object($item) ? (array) $item : $item;
            
            if (is_array($itemArray) && 
                isset($itemArray['type']) && 
                $itemArray['type'] === 'divider' && 
                isset($itemArray['text']) && 
                $itemArray['text'] === $sectionName
            ) {
                $dividerIndex = $index;
                break;
            }
        }
        
        // If divider doesn't exist, create it
        if ($dividerIndex === null) {
            $divider = (object) [
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
        }
        
        // Insert all items right after the divider
        $insertPosition = $dividerIndex + 1;
        foreach ($items as $itemToAdd) {
            array_splice($tabList, $insertPosition, 0, [$itemToAdd]);
            $insertPosition++;
        }
        
        return $tabList;
    }

    private function addCalendarItem(array $tabList): array
    {
        // Define the Calendar URL item
        $calendarItem = (object) [
            'type' => 'url',
            'text' => '$Calendar',
            'url' => '#Calendar',
            'iconClass' => 'ti ti-calendar',
            'color' => null,
            'aclScope' => null,
            'onlyAdmin' => false,
            'id' => '906879'
        ];
        
        // Define the My Activities URL item
        $myActivitiesItem = (object) [
            'type' => 'url',
            'text' => '$Activities',
            'url' => '#Activities',
            'iconClass' => 'ti ti-checklist',
            'color' => null,
            'aclScope' => null,
            'onlyAdmin' => false,
            'id' => '906883'
        ];
        
        // Find and remove existing Calendar and My Activities items
        $indicesToRemove = [];
        
        foreach ($tabList as $index => $item) {
            if ($item === null) {
                $indicesToRemove[] = $index;
                continue;
            }
            
            $itemArray = is_object($item) ? (array) $item : $item;
            
            if (is_array($itemArray) && 
                isset($itemArray['type']) && 
                $itemArray['type'] === 'url' && 
                isset($itemArray['text']) && 
                ($itemArray['text'] === '$Calendar' || $itemArray['text'] === '$Activities')
            ) {
                $indicesToRemove[] = $index;
            }
        }
        
        // Remove in reverse order to preserve indices
        rsort($indicesToRemove);
        foreach ($indicesToRemove as $index) {
            array_splice($tabList, $index, 1);
        }
        
        // Find Home position and insert Calendar/Activities right after it
        $homeIndex = 0;
        
        foreach ($tabList as $index => $item) {
            if ($item === 'Home') {
                $homeIndex = $index;
                break;
            }
        }
        
        // Always insert Calendar and My Activities right after Home
        array_splice($tabList, $homeIndex + 1, 0, [$calendarItem, $myActivitiesItem]);
        
        return $tabList;
    }

    private function addCRMSection(array $tabList): array
    {
        // Remove Contact and Opportunity from everywhere first
        $tabList = array_values(array_filter($tabList, function($item) {
            return !is_string($item) || !in_array($item, ['Contact', 'Opportunity']);
        }));
        
        // Find the Activities URL item (inserted by addCalendarItem)
        $activitiesIndex = null;
        foreach ($tabList as $index => $item) {
            $itemArray = is_object($item) ? (array) $item : $item;
            if (is_array($itemArray) && 
                isset($itemArray['type']) && 
                $itemArray['type'] === 'url' && 
                isset($itemArray['text']) && 
                $itemArray['text'] === '$Activities'
            ) {
                $activitiesIndex = $index;
                break;
            }
        }
        
        // If Activities not found, insert at position 1 (after Home)
        $insertPosition = ($activitiesIndex !== null) ? $activitiesIndex + 1 : 1;
        
        // Remove existing $CRM divider if found
        foreach ($tabList as $index => $item) {
            $itemArray = is_object($item) ? (array) $item : $item;
            if (is_array($itemArray) && 
                isset($itemArray['type']) && 
                $itemArray['type'] === 'divider' && 
                isset($itemArray['text']) && 
                $itemArray['text'] === '$CRM'
            ) {
                array_splice($tabList, $index, 1);
                if ($index < $insertPosition) {
                    $insertPosition--;
                }
                break;
            }
        }
        
        // Create and insert $CRM divider
        $crmDivider = (object) [
            'type' => 'divider',
            'text' => '$CRM',
        ];
        array_splice($tabList, $insertPosition, 0, [$crmDivider]);
        $insertPosition++;
        
        // Insert Contact and Opportunity
        array_splice($tabList, $insertPosition, 0, ['Contact', 'Opportunity']);
        
        return $tabList;
    }

    private function addActivitiesSection(array $tabList): array
    {
        // Use upsertToSection to add/update the $Activities divider with Task, Call, Meeting, Appointment
        return $this->upsertToSection($tabList, '$Activities', ['Task', 'Appointment', 'Call', 'Meeting'], 'end');
    }

    private function addConfigurationSection(array $tabList): array
    {
        // Define the Configurations divider
        $configDivider = (object) [
            'type' => 'divider',
            'text' => '$Configurations',
            'id' => '906873'
        ];

        // Define the Configuration group
        $configGroup = (object) [
            'type' => 'group',
            'text' => '$Configuration',
            'iconClass' => 'ti ti-settings',
            'color' => null,
            'id' => '906874'
        ];
        
        // Find and remove existing Configuration divider and group
        $indicesToRemove = [];
        
        foreach ($tabList as $index => $item) {
            if ($item === null) {
                $indicesToRemove[] = $index;
                continue;
            }
            
            $itemArray = is_object($item) ? (array) $item : $item;
            
            if (!is_array($itemArray) || !isset($itemArray['type'])) {
                continue;
            }
            
            // Check for Configurations divider
            if ($itemArray['type'] === 'divider' && 
                isset($itemArray['text']) && 
                $itemArray['text'] === '$Configurations'
            ) {
                $indicesToRemove[] = $index;
            }
            
            // Check for Configuration group
            if ($itemArray['type'] === 'group' && 
                isset($itemArray['text']) && 
                $itemArray['text'] === '$Configuration'
            ) {
                $indicesToRemove[] = $index;
            }
        }
        
        // Remove in reverse order to preserve indices
        rsort($indicesToRemove);
        foreach ($indicesToRemove as $index) {
            array_splice($tabList, $index, 1);
        }
        
        // Always add Configurations divider and group at the end
        $tabList[] = $configDivider;
        $tabList[] = $configGroup;
        
        return $tabList;
    }
}

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
    }

    private function configureNavbar(): void
    {
        $tabList = $this->config->get('tabList');
        
        if (!is_array($tabList)) {
            return;
        }

        $newTabList = $this->upsertToSection($tabList, '$Records', ['Contact', 'Account', 'Opportunity'], 'beginning');
        $newTabList = $this->addCalendarItem($newTabList);
        $newTabList = $this->addActivitiesSection($newTabList);
        $newTabList = $this->addConfigurationSection($newTabList);
        
        // Filter out null values and re-index
        $newTabList = array_values(array_filter($newTabList, fn($item) => $item !== null));

        if ($newTabList !== $tabList) {
            $this->configWriter->set('tabList', $newTabList);
            $this->configWriter->save();
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
                // Find the end of this section (next divider or end of array)
                for ($i = $index + 1; $i < count($tabList); $i++) {
                    if ($tabList[$i] === null) {
                        continue;
                    }
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
        
        // Find the Conversations group and insert Calendar and My Activities before it
        $conversationsIndex = null;
        
        foreach ($tabList as $index => $item) {
            if ($item === null) {
                continue;
            }
            
            $itemArray = is_object($item) ? (array) $item : $item;
            
            if (is_array($itemArray) && 
                isset($itemArray['type']) && 
                $itemArray['type'] === 'group' && 
                isset($itemArray['text']) && 
                $itemArray['text'] === '$Conversations'
            ) {
                $conversationsIndex = $index;
                break;
            }
        }
        
        if ($conversationsIndex !== null) {
            // Insert Calendar and My Activities before Conversations
            array_splice($tabList, $conversationsIndex, 0, [$calendarItem, $myActivitiesItem]);
        } else {
            // If Conversations doesn't exist, add Calendar and My Activities at position 1 (after Home)
            array_splice($tabList, 1, 0, [$calendarItem, $myActivitiesItem]);
        }
        
        return $tabList;
    }

    private function addActivitiesSection(array $tabList): array
    {
        // Use upsertToSection to add/update the $Activities divider with Task, Call, Meeting
        return $this->upsertToSection($tabList, '$Activities', ['Task', 'Call', 'Meeting'], 'end');
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
            'id' => '906874',
            'itemList' => [
                (object) [
                    'type' => 'url',
                    'text' => '$Funnels',
                    'url' => '#Funnel',
                    'iconClass' => 'ti ti-chart-funnel',
                    'color' => null,
                    'aclScope' => null,
                    'onlyAdmin' => false,
                    'id' => '906877'
                ],
                (object) [
                    'type' => 'url',
                    'text' => '$Import',
                    'url' => '#Import',
                    'iconClass' => 'ti ti-file-import',
                    'color' => null,
                    'aclScope' => null,
                    'onlyAdmin' => false,
                    'id' => '906875'
                ],
                (object) [
                    'type' => 'url',
                    'text' => '$Reports',
                    'url' => '#Report',
                    'iconClass' => 'ti ti-report',
                    'color' => null,
                    'aclScope' => null,
                    'onlyAdmin' => false,
                    'id' => '906876'
                ]
            ]
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

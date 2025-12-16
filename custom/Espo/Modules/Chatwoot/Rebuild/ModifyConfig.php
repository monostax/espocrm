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

namespace Espo\Modules\Chatwoot\Rebuild;

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
    }

    private function configureNavbar(): void
    {
        $tabList = $this->config->get('tabList');
        
        if (!is_array($tabList)) {
            return;
        }

        $newTabList = $this->addTabListSection($tabList);

        if ($newTabList !== $tabList) {
            $this->configWriter->set('tabList', $newTabList);
            $this->configWriter->save();
        }
    }

    private function addTabListSection(array $tabList): array
    {
        // Define the complete Cadastros section
        $cItems = [
            [
                'type' => 'divider',
                'text' => '$Chatwoot',
            ],
            'ChatwootPlatform',
            'ChatwootAccount',
            'ChatwootUser',
            'ChatwootTeam',
            'ChatwootAccountWebhook',
            'ChatwootSyncState'
        ];
        
        // Find existing Chatwoot section
        $sectionStartIndex = null;
        $sectionEndIndex = null;
        
        foreach ($tabList as $index => $item) {
            // Convert object to array for comparison (EspoCRM may store as stdClass)
            $itemArray = is_object($item) ? (array) $item : $item;
            
            // Check if we're at the Chatwoot divider
            if (is_array($itemArray) && 
                isset($itemArray['type']) && 
                $itemArray['type'] === 'divider' && 
                isset($itemArray['text']) && 
                $itemArray['text'] === '$Chatwoot'
            ) {
                $sectionStartIndex = $index;
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
                break;
            }
        }
        
        // If Cadastros section exists, replace it in-place
        if ($sectionStartIndex !== null) {
            // Remove old section
            array_splice($tabList, $sectionStartIndex, $sectionEndIndex - $sectionStartIndex + 1);
            // Insert new section at the same position
            array_splice($tabList, $sectionStartIndex, 0, $cItems);
        } else {
            // Section doesn't exist, add it at the beginning
            array_unshift($tabList, ...$cItems);
        }
        
        return $tabList;
    }
}

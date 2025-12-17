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
 * - Adds/updates the Conversations group in the navbar with Chatwoot conversation links.
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
        // Define the Conversations group
        $conversationsGroup = (object) [
            'type' => 'group',
            'text' => '$Conversations',
            'iconClass' => 'ti ti-messages',
            'color' => null,
            'id' => '853524',
            'itemList' => [
                (object) [
                    'type' => 'url',
                    'text' => '$Inbox',
                    'url' => '#Chatwoot?cwPath=/app/accounts/{{chatwootAccountId}}/inbox-view',
                    'iconClass' => 'ti ti-inbox',
                    'color' => null,
                    'aclScope' => null,
                    'onlyAdmin' => false,
                    'id' => '906874'
                ],
                (object) [
                    'type' => 'url',
                    'text' => '$All',
                    'url' => '#Chatwoot?cwPath=/app/accounts/{{chatwootAccountId}}/dashboard',
                    'iconClass' => 'ti ti-message-down',
                    'color' => null,
                    'aclScope' => null,
                    'onlyAdmin' => false,
                    'id' => '927132'
                ],
                (object) [
                    'type' => 'url',
                    'text' => '$Unattended',
                    'url' => '#Chatwoot?cwPath=/app/accounts/{{chatwootAccountId}}/unattended/conversations',
                    'iconClass' => 'ti ti-message-report',
                    'color' => null,
                    'aclScope' => null,
                    'onlyAdmin' => false,
                    'id' => '442501'
                ],
                (object) [
                    'type' => 'url',
                    'text' => '$Mentions',
                    'url' => '#Chatwoot?cwPath=/app/accounts/{{chatwootAccountId}}/mentions/conversations',
                    'iconClass' => 'ti ti-message-bolt',
                    'color' => null,
                    'aclScope' => null,
                    'onlyAdmin' => false,
                    'id' => '128471'
                ]
            ]
        ];
        
        // Find existing Conversations group
        $existingIndex = null;
        
        foreach ($tabList as $index => $item) {
            // Convert object to array for comparison (EspoCRM may store as stdClass)
            $itemArray = is_object($item) ? (array) $item : $item;
            
            // Check if we're at the Conversations group
            if (is_array($itemArray) && 
                isset($itemArray['type']) && 
                $itemArray['type'] === 'group' && 
                isset($itemArray['text']) && 
                $itemArray['text'] === '$Conversations'
            ) {
                $existingIndex = $index;
                break;
            }
        }
        
        // If Conversations group exists, replace it in-place
        if ($existingIndex !== null) {
            $tabList[$existingIndex] = $conversationsGroup;
        } else {
            // Group doesn't exist, add it at the beginning
            array_unshift($tabList, $conversationsGroup);
        }
        
        return $tabList;
    }
}

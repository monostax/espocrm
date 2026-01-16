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
        
        // Filter out null values and re-index
        $newTabList = array_values(array_filter($newTabList, fn($item) => $item !== null));

        if ($newTabList !== $tabList) {
            $this->configWriter->set('tabList', $newTabList);
            $this->configWriter->save();
        }
    }

    private function addTabListSection(array $tabList): array
    {
        // Define the Conversations divider
        $conversationsDivider = (object) [
            'type' => 'divider',
            'text' => '$Conversations',
            'id' => '853524'
        ];

        // Conversation filter URL items with deep links
        // Custom list view (chatwoot:views/chatwoot-conversation/list) keeps filter dropdown visible
        // URL format: #EntityType/list/primaryFilter=value
        // Note: "All" link removed to avoid double-active state in navbar
        // Users can select "Todas" from the filter dropdown to see all conversations
        $conversationItems = [
            (object) [
                'type' => 'url',
                'text' => '$OpenConversations',
                'url' => '#ChatwootConversation/list/primaryFilter=open',
                // 'iconClass' => 'ti ti-message-exclamation',
                'iconClass' => 'ti ti-circle-dashed',
                'color' => null,
                'aclScope' => 'ChatwootConversation',
                'onlyAdmin' => false,
                'id' => '853526'
            ],
            (object) [
                'type' => 'url',
                'text' => '$PendingConversations',
                'url' => '#ChatwootConversation/list/primaryFilter=pending',
                // 'iconClass' => 'ti ti-message-pause',
                'iconClass' => 'ti ti-circle-half-2',
                'color' => null,
                'aclScope' => 'ChatwootConversation',
                'onlyAdmin' => false,
                'id' => '853527'
            ],
            (object) [
                'type' => 'url',
                'text' => '$SnoozedConversations',
                'url' => '#ChatwootConversation/list/primaryFilter=snoozed',
                // 'iconClass' => 'ti ti-message-off',
                'iconClass' => 'ti ti-bell-off',
                'color' => null,
                'aclScope' => 'ChatwootConversation',
                'onlyAdmin' => false,
                'id' => '853529'
            ],
            (object) [
                'type' => 'url',
                'text' => '$ResolvedConversations',
                'url' => '#ChatwootConversation/list/primaryFilter=resolved',
                // 'iconClass' => 'ti ti-message-check',
                'iconClass' => 'ti ti-circle-check-filled',
                'color' => null,
                'aclScope' => 'ChatwootConversation',
                'onlyAdmin' => false,
                'id' => '853528'
            ],
        ];

        // Previous implementation using Chatwoot iframe paths (commented out):
        // $conversationsGroup = (object) [
        //     'type' => 'group',
        //     'text' => '$Conversations',
        //     'iconClass' => 'ti ti-messages',
        //     'color' => null,
        //     'id' => '853524',
        //     'itemList' => [
        //         (object) [
        //             'type' => 'url',
        //             'text' => '$Inbox',
        //             'url' => '#Chatwoot?cwPath=/app/accounts/{{chatwootAccountId}}/inbox-view',
        //             'iconClass' => 'ti ti-inbox',
        //             'color' => null,
        //             'aclScope' => null,
        //             'onlyAdmin' => false,
        //             'id' => '906874'
        //         ],
        //         (object) [
        //             'type' => 'url',
        //             'text' => '$All',
        //             'url' => '#Chatwoot?cwPath=/app/accounts/{{chatwootAccountId}}/dashboard',
        //             'iconClass' => 'ti ti-message-down',
        //             'color' => null,
        //             'aclScope' => null,
        //             'onlyAdmin' => false,
        //             'id' => '927132'
        //         ],
        //         (object) [
        //             'type' => 'url',
        //             'text' => '$Unattended',
        //             'url' => '#Chatwoot?cwPath=/app/accounts/{{chatwootAccountId}}/unattended/conversations',
        //             'iconClass' => 'ti ti-message-report',
        //             'color' => null,
        //             'aclScope' => null,
        //             'onlyAdmin' => false,
        //             'id' => '442501'
        //         ],
        //         (object) [
        //             'type' => 'url',
        //             'text' => '$Mentions',
        //             'url' => '#Chatwoot?cwPath=/app/accounts/{{chatwootAccountId}}/mentions/conversations',
        //             'iconClass' => 'ti ti-message-bolt',
        //             'color' => null,
        //             'aclScope' => null,
        //             'onlyAdmin' => false,
        //             'id' => '128471'
        //         ]
        //     ]
        // ];
        
        // Find and remove existing Conversations divider/group and its items
        $indicesToRemove = [];
        $dividerIndex = null;
        
        foreach ($tabList as $index => $item) {
            if ($item === null) {
                $indicesToRemove[] = $index;
                continue;
            }
            
            $itemArray = is_object($item) ? (array) $item : $item;
            
            if (!is_array($itemArray) || !isset($itemArray['type'])) {
                continue;
            }
            
            // Check for Conversations divider
            if ($itemArray['type'] === 'divider' && 
                isset($itemArray['text']) && 
                $itemArray['text'] === '$Conversations'
            ) {
                $indicesToRemove[] = $index;
                $dividerIndex = $index;
            }
            
            // Check for old Conversations group (to remove it)
            if ($itemArray['type'] === 'group' && 
                isset($itemArray['text']) && 
                $itemArray['text'] === '$Conversations'
            ) {
                $indicesToRemove[] = $index;
                $dividerIndex = $index;
            }
            
            // Check for conversation URL items (by id pattern 8535xx)
            if ($itemArray['type'] === 'url' && 
                isset($itemArray['id']) && 
                preg_match('/^8535\d{2}$/', $itemArray['id'])
            ) {
                $indicesToRemove[] = $index;
            }
        }
        
        // Remove in reverse order to preserve indices
        rsort($indicesToRemove);
        foreach ($indicesToRemove as $index) {
            array_splice($tabList, $index, 1);
        }
        
        // Find the insert position: after Calendar/Activities items, or after Home
        $insertPosition = 1; // Default: after Home
        
        foreach ($tabList as $index => $item) {
            if ($item === null) {
                continue;
            }
            
            $itemArray = is_object($item) ? (array) $item : $item;
            
            // Look for Calendar (id 906879) or Activities (id 906883) items from Global module
            if (is_array($itemArray) && 
                isset($itemArray['type']) && 
                $itemArray['type'] === 'url' && 
                isset($itemArray['id']) && 
                ($itemArray['id'] === '906879' || $itemArray['id'] === '906883')
            ) {
                // Insert after the last Calendar/Activities item found
                $insertPosition = $index + 1;
            }
        }
        
        // Add Conversations divider and items after Calendar/Activities (or after Home)
        array_splice($tabList, $insertPosition, 0, [$conversationsDivider, ...$conversationItems]);
        
        return $tabList;
    }
}

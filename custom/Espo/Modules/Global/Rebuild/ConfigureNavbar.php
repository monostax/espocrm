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
 * This moves "Call" from the Activities section to the top section.
 * Runs automatically during system rebuild.
 */
class ConfigureNavbar implements RebuildAction
{
    private const CACHE_KEY = 'navbarConfigured';

    public function __construct(
        private Config $config,
        private ConfigWriter $configWriter,
        private Log $log
    ) {}

    public function process(): void
    {
        // Check if already configured (to avoid re-running on every rebuild)
        if ($this->config->get(self::CACHE_KEY)) {
            return;
        }

        // Get current tabList from config
        $tabList = $this->config->get('tabList');
        
        if (!is_array($tabList)) {
            $this->log->warning('Global Module: tabList is not an array, skipping navbar configuration.');
            return;
        }

        // Check if this looks like the default tabList
        $hasCallInActivities = $this->hasCallInActivitiesSection($tabList);
        
        if (!$hasCallInActivities) {
            // Already modified or user has customized
            $this->configWriter->set(self::CACHE_KEY, true);
            $this->configWriter->save();
            return;
        }

        // Modify the tabList
        $newTabList = $this->moveCallToTopSection($tabList);
        
        if ($newTabList === $tabList) {
            // No changes needed
            $this->configWriter->set(self::CACHE_KEY, true);
            $this->configWriter->save();
            return;
        }

        // Save the new tabList
        $this->configWriter->set('tabList', $newTabList);
        $this->configWriter->set(self::CACHE_KEY, true);
        $this->configWriter->save();

        $this->log->info('Global Module: Successfully configured navbar - moved Call to top section.');
    }

    /**
     * Check if Call is in the Activities section
     */
    private function hasCallInActivitiesSection(array $tabList): bool
    {
        $inActivitiesSection = false;
        
        foreach ($tabList as $item) {
            // Check if we're entering Activities section
            if (is_object($item) && 
                isset($item->type) && 
                $item->type === 'divider' && 
                isset($item->text) && 
                $item->text === '$Activities'
            ) {
                $inActivitiesSection = true;
                continue;
            }
            
            // Check if we're leaving Activities section (next divider)
            if ($inActivitiesSection && 
                is_object($item) && 
                isset($item->type) && 
                $item->type === 'divider'
            ) {
                $inActivitiesSection = false;
                continue;
            }
            
            // If we're in Activities section and find Call, return true
            if ($inActivitiesSection && $item === 'Call') {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Move Call from Activities section to top section (after CRM divider)
     */
    private function moveCallToTopSection(array $tabList): array
    {
        $newTabList = [];
        $callRemoved = false;
        $inActivitiesSection = false;
        $crmDividerPassed = false;
        $callInserted = false;
        
        foreach ($tabList as $item) {
            // Check if we're at CRM divider
            if (is_object($item) && 
                isset($item->type) && 
                $item->type === 'divider' && 
                isset($item->text) && 
                $item->text === '$CRM'
            ) {
                $newTabList[] = $item;
                $crmDividerPassed = true;
                continue;
            }
            
            // Insert Call right after CRM divider and before first entity
            if ($crmDividerPassed && 
                !$callInserted && 
                !is_object($item) && 
                $item !== '_delimiter_' &&
                $item !== 'Home'
            ) {
                $newTabList[] = 'Call';
                $callInserted = true;
            }
            
            // Check if we're entering Activities section
            if (is_object($item) && 
                isset($item->type) && 
                $item->type === 'divider' && 
                isset($item->text) && 
                $item->text === '$Activities'
            ) {
                $inActivitiesSection = true;
                $newTabList[] = $item;
                continue;
            }
            
            // Check if we're leaving Activities section
            if ($inActivitiesSection && 
                is_object($item) && 
                isset($item->type) && 
                $item->type === 'divider'
            ) {
                $inActivitiesSection = false;
            }
            
            // Skip Call in Activities section
            if ($inActivitiesSection && $item === 'Call') {
                $callRemoved = true;
                continue;
            }
            
            $newTabList[] = $item;
        }
        
        // If Call wasn't inserted yet (edge case), add it at the beginning
        if ($callRemoved && !$callInserted) {
            // Insert after Home if it exists, otherwise at the start
            $homeIndex = array_search('Home', $newTabList);
            if ($homeIndex !== false) {
                array_splice($newTabList, $homeIndex + 1, 0, ['Call']);
            } else {
                array_unshift($newTabList, 'Call');
            }
        }
        
        return $newTabList;
    }
}


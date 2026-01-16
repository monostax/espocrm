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
 * - Adds $Clinica section with CAgendamento, CPaciente, and CProfissional to the navbar (after $CRM section).
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

        $newTabList = $this->addClinicaSection($tabList);

        if ($newTabList !== $tabList) {
            $this->configWriter->set('tabList', $newTabList);
            $this->configWriter->save();
            $this->log->info('Clinica Module: Successfully configured navbar.');
        }
    }

    /**
     * Add $Clinica section with CAgendamento, CPaciente, and CProfissional after $CRM section.
     */
    private function addClinicaSection(array $tabList): array
    {
        $items = ['CAgendamento', 'CPaciente', 'CProfissional'];
        
        // Remove items from anywhere they exist
        $tabList = array_values(array_filter($tabList, function($item) use ($items) {
            return !is_string($item) || !in_array($item, $items);
        }));
        
        // Find and remove existing $Clinica divider
        foreach ($tabList as $index => $item) {
            $itemArray = is_object($item) ? (array) $item : $item;
            if (is_array($itemArray) && 
                isset($itemArray['type']) && 
                $itemArray['type'] === 'divider' && 
                isset($itemArray['text']) && 
                $itemArray['text'] === '$Clinica'
            ) {
                array_splice($tabList, $index, 1);
                break;
            }
        }
        
        // Find where to insert: after $CRM section (before next divider)
        $insertPosition = null;
        $crmDividerIndex = null;
        
        foreach ($tabList as $index => $item) {
            $itemArray = is_object($item) ? (array) $item : $item;
            if (is_array($itemArray) && 
                isset($itemArray['type']) && 
                $itemArray['type'] === 'divider' && 
                isset($itemArray['text']) && 
                $itemArray['text'] === '$CRM'
            ) {
                $crmDividerIndex = $index;
                break;
            }
        }
        
        if ($crmDividerIndex !== null) {
            // Find the next divider after $CRM
            for ($i = $crmDividerIndex + 1; $i < count($tabList); $i++) {
                $item = $tabList[$i];
                $itemArray = is_object($item) ? (array) $item : $item;
                if (is_array($itemArray) && 
                    isset($itemArray['type']) && 
                    $itemArray['type'] === 'divider'
                ) {
                    $insertPosition = $i;
                    break;
                }
            }
            if ($insertPosition === null) {
                $insertPosition = count($tabList);
            }
        } else {
            // If no $CRM section found, insert at end
            $insertPosition = count($tabList);
        }
        
        // Insert $Clinica divider and items
        $divider = (object) [
            'type' => 'divider',
            'text' => '$Clinica',
        ];
        array_splice($tabList, $insertPosition, 0, [$divider, ...$items]);
        
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

<?php
/************************************************************************
 * This file is part of EspoCRM.
 *
 * EspoCRM – Open Source CRM application.
 * Copyright (C) 2014-2026 EspoCRM, Inc.
 * Website: https://www.espocrm.com
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 *
 * The interactive user interfaces in modified source and object code versions
 * of this program must display Appropriate Legal Notices, as required under
 * Section 5 of the GNU Affero General Public License version 3.
 *
 * In accordance with Section 7(b) of the GNU Affero General Public License version 3,
 * these Appropriate Legal Notices must retain the display of the "EspoCRM" word.
 ************************************************************************/

namespace Espo\Modules\Global\Core\Utils\Metadata\AdditionalBuilder;

use Espo\Core\Utils\File\Manager as FileManager;
use Espo\Core\Utils\Json;
use Espo\Core\Utils\Metadata\AdditionalBuilder;
use Espo\Core\Utils\Module;
use stdClass;

/**
 * Merges adminForUserPanel metadata from all custom modules.
 *
 * This ensures that panel definitions from multiple modules (e.g., Global, Chatwoot)
 * are properly merged instead of being overwritten.
 */
class AdminForUserPanel implements AdditionalBuilder
{
    public function build(stdClass $data): void
    {
        // Create FileManager and Module instances (no DI available in AdditionalBuilder)
        $fileManager = new FileManager();
        $module = new Module($fileManager);
        $moduleList = $module->getOrderedList();

        $mergedPanels = new stdClass();

        // Scan all modules for adminForUserPanel.json files
        foreach ($moduleList as $moduleName) {
            $filePath = "custom/Espo/Modules/{$moduleName}/Resources/metadata/app/adminForUserPanel.json";

            if (!file_exists($filePath)) {
                continue;
            }

            $fileContent = file_get_contents($filePath);
            if ($fileContent === false) {
                continue;
            }

            try {
                $panelData = Json::decode($fileContent);
            } catch (\JsonException $e) {
                continue;
            }

            $this->mergePanels($mergedPanels, $panelData);
        }

        // Inject merged data into metadata if we found any panels
        if (count(get_object_vars($mergedPanels)) > 0) {
            if (!isset($data->app)) {
                $data->app = new stdClass();
            }
            $data->app->adminForUserPanel = $mergedPanels;
        }
    }

    /**
     * Modules contribute to shared task groups and sections. Keep the first
     * definition's labels/order and append destinations within each section.
     */
    private function mergePanels(stdClass $target, stdClass $source): void
    {
        foreach (get_object_vars($source) as $key => $definition) {
            if (!isset($target->$key)) {
                $target->$key = new stdClass();
            }

            $panel = $target->$key;

            foreach (get_object_vars($definition) as $property => $value) {
                if ($property === 'sections') {
                    $panel->sections ??= new stdClass();
                    $this->mergePanels($panel->sections, $value);

                    continue;
                }

                if ($property === 'itemList') {
                    $panel->itemList = array_merge($panel->itemList ?? [], $value);

                    continue;
                }

                $panel->$property ??= $value;
            }
        }
    }
}

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

namespace Espo\Core;

use Espo\Core\Hook\DataProvider;
use Espo\Core\Hook\GeneralInvoker;
use Espo\Core\Utils\Log;

/**
 * Runs hooks. E.g. beforeSave, afterSave. Hooks can be located in a folder
 * that matches a certain entity type or in the `Common` folder.
 * Common hooks are applied to all entity types.
 *
 * - `Espo\Hooks\Common\MyHook` – a common hook;
 * - `Espo\Hooks\{EntityType}\MyHook` – an entity type specific hook;
 * - `Espo\Modules\{ModuleName}\Hooks\{EntityType}\MyHook` – in a module.
 *
 * @link https://docs.espocrm.com/development/hooks/
 */
class HookManager
{
    /** @var ?array<string, array<string, mixed>> */
    private $data = null;

    private bool $isDisabled = false;

    /** @var array<string, class-string[]> */
    private $hookListHash = [];

    /** @var array<class-string, object> */
    private $hooks;

    public function __construct(
        private InjectableFactory $injectableFactory,
        private Log $log,
        private GeneralInvoker $generalInvoker,
        private DataProvider $dataProvider,
    ) {}

    /**
     * @param string $scope A scope (entity type).
     * @param string $hookName A hook name.
     * @param mixed $injection A subject (usually an entity).
     * @param array<string, mixed> $options Options.
     * @param array<string, mixed> $hookData Additional hook data.
     */
    public function process(
        string $scope,
        string $hookName,
        mixed $injection = null,
        array $options = [],
        array $hookData = [],
    ): void {

        if ($this->isDisabled) {
            return;
        }

        if ($this->data === null) {
            $this->loadHooks();
        }

        $hookList = $this->getHookList($scope, $hookName);

        if ($hookList === []) {
            return;
        }

        foreach ($hookList as $className) {
            if (empty($this->hooks[$className])) {
                $this->hooks[$className] = $this->createHookByClassName($className);
            }

            $hook = $this->hooks[$className];

            $this->generalInvoker->invoke(
                hook: $hook,
                name: $hookName,
                subject: $injection,
                options: $options,
                hookData: $hookData,
            );
        }
    }

    /**
     * Disable hook processing.
     */
    public function disable(): void
    {
        $this->isDisabled = true;
    }

    /**
     * Enable hook processing.
     */
    public function enable(): void
    {
        $this->isDisabled = false;
    }

    /**
     * Clear hook cache. Forces hooks to be reloaded from filesystem.
     */
    public function clearCache(): void
    {
        $this->data = null;
        $this->hookListHash = [];
        $this->dataProvider->clear();
    }

    /**
     * Preload and cache hooks. Useful after rebuild to ensure hooks are discovered and cached.
     */
    public function preloadHooks(): void
    {
        $this->loadHooks();
    }

    private function loadHooks(): void
    {
        $this->data = $this->dataProvider->get();
    }

    /**
     * @param class-string $className
     */
    private function createHookByClassName(string $className): object
    {
        if (!class_exists($className)) {
            $this->log->error("Hook class '$className' does not exist.");
        }

        return $this->injectableFactory->create($className);
    }


    /**
     * Get sorted hook list.
     *
     * @return class-string[]
     */
    private function getHookList(string $scope, string $hookName): array
    {
        $key = $scope . '_' . $hookName;

        if (!isset($this->hookListHash[$key])) {
            $hookList = [];

            if (isset($this->data['Common'][$hookName])) {
                $hookList = $this->data['Common'][$hookName];
            }

            if (isset($this->data[$scope][$hookName])) {
                $hookList = array_merge($hookList, $this->data[$scope][$hookName]);

                usort($hookList, [$this, 'cmpHooks']);
            }

            $normalizedList = [];

            foreach ($hookList as $hookData) {
                $normalizedList[] = $hookData['className'];
            }

            $this->hookListHash[$key] = $normalizedList;
        }

        return $this->hookListHash[$key];
    }

    /**
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     */
    private function cmpHooks($a, $b): int
    {
        if ($a['order'] == $b['order']) {
            return 0;
        }

        return ($a['order'] < $b['order']) ? -1 : 1;
    }
}

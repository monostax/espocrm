<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureAutomation\Rebuild;

use Espo\Core\Rebuild\RebuildAction;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Config\ConfigWriter;
use Espo\Core\Utils\Log;

class ConfigureNavbar implements RebuildAction
{
    private const GROUP_ID = 'automation-group';

    /** @var list<string> */
    private const GROUP_ITEMS = [
        'Automation',
        'AutomationRun',
    ];

    public function __construct(
        private Config $config,
        private ConfigWriter $configWriter,
        private Log $log,
    ) {}

    public function process(): void
    {
        $tabList = $this->config->get('tabList');

        if (!is_array($tabList)) {
            $this->log->warning('FeatureAutomation.ConfigureNavbar: tabList missing; skipping.');

            return;
        }

        $newTabList = $this->upsertGroup($tabList);

        if (json_encode($newTabList) !== json_encode($tabList)) {
            $this->configWriter->set('tabList', $newTabList);
            $this->configWriter->save();
            $this->log->info('FeatureAutomation.ConfigureNavbar: tabList updated.');
        }
    }

    /**
     * @param array<int, mixed> $tabList
     * @return array<int, mixed>
     */
    private function upsertGroup(array $tabList): array
    {
        $tabList = array_values(array_filter(
            $tabList,
            fn($item) => !$this->isOurGroup($item),
        ));

        $tabList[] = (object) [
            'type' => 'group',
            'text' => '$Automations',
            'iconClass' => 'ti ti-bolt',
            'color' => null,
            'id' => self::GROUP_ID,
            'itemList' => self::GROUP_ITEMS,
        ];

        return $tabList;
    }

    private function isOurGroup(mixed $item): bool
    {
        if ($item === null) {
            return true;
        }

        $arr = is_object($item) ? (array) $item : (is_array($item) ? $item : null);
        if (!is_array($arr)) {
            return false;
        }

        return ($arr['type'] ?? null) === 'group' && ($arr['id'] ?? null) === self::GROUP_ID;
    }
}

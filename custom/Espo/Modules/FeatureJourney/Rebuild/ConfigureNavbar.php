<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureJourney\Rebuild;

use Espo\Core\Rebuild\RebuildAction;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Config\ConfigWriter;
use Espo\Core\Utils\Log;

/**
 * Idempotently installs the "Journeys" tab group (Journey, JourneyRecord).
 * Mirror of FeatureTrackingEvent\Rebuild\ConfigureNavbar.
 */
class ConfigureNavbar implements RebuildAction
{
    private const GROUP_ID = 'journey-group';

    /** @var list<string> */
    private const GROUP_ITEMS = [
        'Journey',
        'JourneyRecord',
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
            $this->log->warning('FeatureJourney.ConfigureNavbar: tabList missing or not an array; skipping.');

            return;
        }

        $newTabList = $this->upsertGroup($tabList);

        if (json_encode($newTabList) !== json_encode($tabList)) {
            $this->configWriter->set('tabList', $newTabList);
            $this->configWriter->save();

            $this->log->info('FeatureJourney.ConfigureNavbar: tabList updated with "Journeys" group.');
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

        $group = (object) [
            'type' => 'group',
            'text' => '$Journeys',
            'iconClass' => 'ti ti-route',
            'color' => null,
            'id' => self::GROUP_ID,
            'itemList' => self::GROUP_ITEMS,
        ];

        $tabList[] = $group;

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

        $type = $arr['type'] ?? null;
        $id = $arr['id'] ?? null;

        return $type === 'group' && $id === self::GROUP_ID;
    }
}

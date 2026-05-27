<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureTrackingEvent\Rebuild;

use Espo\Core\Rebuild\RebuildAction;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Config\ConfigWriter;
use Espo\Core\Utils\Log;

/**
 * Rebuild action that installs the "Tracking Events" tab group in the navbar.
 *
 * Adds (idempotently) a `{type: 'group'}` entry to `Settings.tabList` with
 * three children: TrackingSource, TrackingEventType, TrackingEvent.
 *
 * The group is identified by `id = 'tracking-event-group'` and is removed +
 * re-added on every rebuild so that future edits to its shape are picked
 * up. Each tenant has its own `Settings` row (k8s/db isolation), so this
 * mutation is naturally tenant-scoped.
 *
 * Mirror of `Espo\Modules\FeatureMetaLeadAds\Rebuild\ConfigureNavbar`.
 *
 * The translation key `$TrackingEvents` resolves through `i18n/{locale}/
 * Global.json` (labels.TrackingEvents).
 */
class ConfigureNavbar implements RebuildAction
{
    private const GROUP_ID = 'tracking-event-group';

    /** @var list<string> */
    private const GROUP_ITEMS = [
        'TrackingSource',
        'TrackingEventType',
        'TrackingEvent',
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
            $this->log->warning('FeatureTrackingEvent.ConfigureNavbar: tabList missing or not an array; skipping.');

            return;
        }

        $newTabList = $this->upsertGroup($tabList);

        if (json_encode($newTabList) !== json_encode($tabList)) {
            $this->configWriter->set('tabList', $newTabList);
            $this->configWriter->save();

            $this->log->info('FeatureTrackingEvent.ConfigureNavbar: tabList updated with "Tracking Events" group.');
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
            'type'      => 'group',
            'text'      => '$TrackingEvents',
            'iconClass' => 'ti ti-broadcast',
            'color'     => null,
            'id'        => self::GROUP_ID,
            'itemList'  => self::GROUP_ITEMS,
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
        $id   = $arr['id']   ?? null;

        return $type === 'group' && $id === self::GROUP_ID;
    }
}

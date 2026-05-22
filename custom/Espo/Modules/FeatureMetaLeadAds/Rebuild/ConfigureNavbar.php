<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureMetaLeadAds\Rebuild;

use Espo\Core\Rebuild\RebuildAction;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Config\ConfigWriter;
use Espo\Core\Utils\Log;

/**
 * Rebuild action that installs the "Meta Lead Ads" tab group in the navbar.
 *
 * Adds (idempotently) a `{type: 'group'}` entry to `Settings.tabList` with
 * three children: MetaFacebookPage, MetaLeadForm, MetaLeadgenEvent.
 *
 * The group is identified by `id = 'meta-lead-ads-group'` and is removed +
 * re-added on every rebuild so that future edits to its shape are picked
 * up. Each tenant has its own `Settings` row (k8s/db isolation), so this
 * mutation is naturally tenant-scoped.
 *
 * Mirror of `Espo\Modules\Chatwoot\Rebuild\ModifyConfig` (which uses
 * `type: 'divider'` + flat URL items rather than a collapsible group).
 *
 * The translation key `$MetaLeadAds` resolves through `i18n/{locale}/
 * Global.json`. The labels file already defines:
 *   - en_US: "Meta Lead Ads": "Meta Lead Ads"
 *   - pt_BR: "Meta Lead Ads": "Meta Lead Ads"
 * If the navbar shows the raw key `$MetaLeadAds`, add it under
 * `labels.MetaLeadAds` in Global.json.
 */
class ConfigureNavbar implements RebuildAction
{
    private const GROUP_ID = 'meta-lead-ads-group';

    /** @var list<string> */
    private const GROUP_ITEMS = [
        'MetaFacebookPage',
        'MetaLeadForm',
        'MetaLeadgenEvent',
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
            $this->log->warning('FeatureMetaLeadAds.ConfigureNavbar: tabList missing or not an array; skipping.');

            return;
        }

        $newTabList = $this->upsertGroup($tabList);

        // Compare by JSON shape — objects rebuilt via (object) [...] always differ via strict equality.
        if (json_encode($newTabList) !== json_encode($tabList)) {
            $this->configWriter->set('tabList', $newTabList);
            $this->configWriter->save();

            $this->log->info('FeatureMetaLeadAds.ConfigureNavbar: tabList updated with "Meta Lead Ads" group.');
        }
    }

    /**
     * @param array<int, mixed> $tabList
     * @return array<int, mixed>
     */
    private function upsertGroup(array $tabList): array
    {
        // 1) Remove any existing instance of our group.
        $tabList = array_values(array_filter(
            $tabList,
            fn($item) => !$this->isOurGroup($item),
        ));

        // 2) Build the fresh group object.
        $group = (object) [
            'type'      => 'group',
            'text'      => '$MetaLeadAds',
            'iconClass' => 'ti ti-brand-meta',
            'color'     => null,
            'id'        => self::GROUP_ID,
            'itemList'  => self::GROUP_ITEMS,
        ];

        // 3) Append at the end (admin can drag-reorder via UI).
        $tabList[] = $group;

        return $tabList;
    }

    private function isOurGroup(mixed $item): bool
    {
        if ($item === null) {
            return true; // also strip null leftovers
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

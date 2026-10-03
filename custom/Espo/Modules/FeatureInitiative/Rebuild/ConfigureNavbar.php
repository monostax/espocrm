<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureInitiative\Rebuild;

use Espo\Core\Rebuild\RebuildAction;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Config\ConfigWriter;

class ConfigureNavbar implements RebuildAction
{
    public function __construct(private Config $config, private ConfigWriter $configWriter) {}

    public function process(): void
    {
        $tabs = $this->config->get('tabList');

        if (!is_array($tabs)) {
            return;
        }

        // Preserve existing navigation customizations and positions on subsequent rebuilds.
        foreach ($tabs as $tab) {
            if ((is_object($tab) || is_array($tab)) && (((array) $tab)['id'] ?? null) === 'initiative-group') {
                return;
            }
        }

        $tabs[] = (object) [
            'type' => 'group',
            'text' => '$Initiatives',
            'iconClass' => 'fas fa-flag',
            'id' => 'initiative-group',
            'itemList' => ['Initiative', 'InitiativeType'],
        ];

        $this->configWriter->set('tabList', $tabs);
        $this->configWriter->save();
    }
}

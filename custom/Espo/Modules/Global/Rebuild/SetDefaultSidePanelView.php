<?php
/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

namespace Espo\Modules\Global\Rebuild;

use Espo\Core\Rebuild\RebuildAction;
use Espo\Core\Utils\Metadata;

/**
 * Sets the defaultSidePanelView for all entities to use our custom panel with avatar support.
 * Runs automatically during system rebuild.
 */
class SetDefaultSidePanelView implements RebuildAction
{
    public function __construct(
        private Metadata $metadata
    ) {}

    private const TARGET_VIEW = 'global:views/record/panels/default-side';

    public function process(): void
    {
        $scopes = $this->metadata->get(['scopes']) ?? [];
        $changed = false;

        foreach ($scopes as $entityType => $scopeDefs) {
            // Only process entity scopes (not disabled, has entity = true)
            if (empty($scopeDefs['entity'])) {
                continue;
            }

            // Skip if defaultSidePanelDisabled is true
            if ($this->metadata->get(['clientDefs', $entityType, 'defaultSidePanelDisabled'])) {
                continue;
            }

            // Skip if already set — avoids rewriting clientDefs JSON on every
            // rebuild, which otherwise creates a self-triggering loop with the
            // dev file watcher (see container/localhost.entrypoint.sh).
            $current = $this->metadata->get(['clientDefs', $entityType, 'defaultSidePanelView']);
            if ($current === self::TARGET_VIEW) {
                continue;
            }

            $this->metadata->set('clientDefs', $entityType, [
                'defaultSidePanelView' => self::TARGET_VIEW,
            ]);
            $changed = true;
        }

        if ($changed) {
            $this->metadata->save();
        }
    }
}





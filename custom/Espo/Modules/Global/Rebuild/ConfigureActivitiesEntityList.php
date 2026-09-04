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

/**
 * Ensures the activity-related entity lists in config contain the entity
 * types Monostax relies on. These lists drive:
 *
 * - `activitiesEntityList`  -> "Activities (Planned)" panels
 * - `historyEntityList`     -> "Activities (Held)" panels (Task with
 *                              status Completed must show up here)
 * - `calendarEntityList`    -> Calendar / scheduler
 * - `busyRangesEntityList`  -> Free/busy lookups (used by the Chatwoot
 *                              calendar tool to avoid overlapping Appointments)
 *
 * Runs automatically during system rebuild. Existing extra entries are
 * kept; only the required ones are appended.
 */
class ConfigureActivitiesEntityList implements RebuildAction
{
    private const REQUIRED = [
        'activitiesEntityList' => ['Meeting', 'Call', 'Task', 'Appointment'],
        'historyEntityList' => ['Meeting', 'Call', 'Email', 'Task', 'Appointment'],
        'calendarEntityList' => ['Meeting', 'Call', 'Task', 'Appointment'],
        'busyRangesEntityList' => ['Meeting', 'Call', 'Appointment'],
    ];

    /** Icon buttons for Meeting, Call, Task, Appointment + Compose Email. */
    private const CREATE_BUTTON_MAX_COUNT = 5;

    public function __construct(
        private Config $config,
        private ConfigWriter $configWriter
    ) {}

    public function process(): void
    {
        $modified = false;

        foreach (self::REQUIRED as $param => $required) {
            $current = $this->config->get($param);

            if (!is_array($current)) {
                $current = [];
            }

            $new = array_values(array_unique(array_merge($current, $required)));

            if ($new !== $current) {
                $this->configWriter->set($param, $new);
                $modified = true;
            }
        }

        if ($this->config->get('activitiesCreateButtonMaxCount') !== self::CREATE_BUTTON_MAX_COUNT) {
            $this->configWriter->set('activitiesCreateButtonMaxCount', self::CREATE_BUTTON_MAX_COUNT);
            $modified = true;
        }

        if ($modified) {
            $this->configWriter->save();
        }
    }
}

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

namespace Espo\Modules\Chatwoot\Tools\Billing;

use DateTime;
use DateTimeZone;
use Espo\Core\Utils\Config;

/**
 * Timezone-aware DAY:runAt expression shared by billing + engaged reports.
 *
 * Mirrors ConversationsEngaged* — buckets must align with runtime date
 * filters (which honor Config timeZone).
 */
final class DayExpression
{
    public function __construct(
        private Config $config,
    ) {}

    public function build(string $attribute = 'runAt'): string
    {
        $offset = $this->getTimeZoneOffset();

        if ($offset === 0 || $offset === 0.0) {
            return 'DAY:' . $attribute;
        }

        return "DAY:TZ:({$attribute},{$offset})";
    }

    /**
     * @return float|int
     */
    public function getTimeZoneOffset()
    {
        $timeZone = $this->config->get('timeZone', 'UTC');

        if ($timeZone === 'UTC') {
            return 0;
        }

        try {
            $tz = new DateTimeZone($timeZone);
            $anchor = (new DateTime('now', $tz))->modify('first day of january');

            return $tz->getOffset($anchor) / 3600;
        } catch (\Throwable) {
            return 0;
        }
    }
}

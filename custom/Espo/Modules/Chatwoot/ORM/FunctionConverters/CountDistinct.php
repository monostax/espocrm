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

namespace Espo\Modules\Chatwoot\ORM\FunctionConverters;

use Espo\ORM\QueryComposer\Part\FunctionConverter;
use RuntimeException;

/**
 * `COUNT_DISTINCT:` ORM expression → SQL `COUNT(DISTINCT ...)`.
 *
 * Registered via `Resources/metadata/app/orm.json` for both MySQL and
 * PostgreSQL. Identical syntax on both engines, so a single converter
 * suffices.
 *
 * Usage:
 *   Expression::create('COUNT_DISTINCT:conversationId')
 *
 * Used by `Espo\Modules\Chatwoot\Reports\ConversationsEngagedByTenantPerDay`
 * to count unique conversations engaged by the AI per (tenant, day) bucket.
 * Espo's built-in aggregate whitelist does not include COUNT_DISTINCT, so
 * we extend it through the FunctionConverter mechanism instead of patching
 * the core whitelist. The Advanced Pack grid report builder still won't
 * recognise the function from a saved report's `columns`, but the internal
 * report class is free to use it directly.
 */
class CountDistinct implements FunctionConverter
{
    public function convert(string ...$argumentList): string
    {
        if (count($argumentList) < 1) {
            throw new RuntimeException("COUNT_DISTINCT requires at least one argument.");
        }

        // Accept multiple columns (composite distinct) — emitted as
        // COUNT(DISTINCT a, b). Supported by both MySQL and PostgreSQL.
        return 'COUNT(DISTINCT ' . implode(', ', $argumentList) . ')';
    }
}

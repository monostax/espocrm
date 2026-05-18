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

namespace Espo\Modules\Chatwoot\Classes\Select\ChatwootAccountUserMembership\PrimaryFilters;

use Espo\Core\Select\Primary\Filter;
use Espo\ORM\Query\SelectBuilder;

/**
 * Primary filter to show only AI-driven ChatwootAccountUserMemberships.
 *
 * Used by the `Opportunity.followupAiAgent` link field's lookup dialog
 * to restrict candidates to AI agent memberships only.
 *
 * @noinspection PhpUnused
 */
class AiOnly implements Filter
{
    public function apply(SelectBuilder $queryBuilder): void
    {
        $queryBuilder->where(['isAI' => true]);
    }
}

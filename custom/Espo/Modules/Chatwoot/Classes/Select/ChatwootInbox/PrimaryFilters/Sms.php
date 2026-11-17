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

namespace Espo\Modules\Chatwoot\Classes\Select\ChatwootInbox\PrimaryFilters;

use Espo\Core\Select\Primary\Filter;
use Espo\ORM\Query\SelectBuilder;

/**
 * SMS inboxes (Android gateway, Vonage…). They have no integration row, so the
 * channel comes from the inbox itself. Used by the journey "Send SMS" inbox picker.
 */
class Sms implements Filter
{
    public function apply(SelectBuilder $queryBuilder): void
    {
        $queryBuilder->where(['remoteChannelType' => 'Channel::Sms']);
    }
}

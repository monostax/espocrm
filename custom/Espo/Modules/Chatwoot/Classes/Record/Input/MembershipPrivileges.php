<?php
/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2026 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

namespace Espo\Modules\Chatwoot\Classes\Record\Input;

use Espo\Core\Record\Input\Data;
use Espo\Core\Record\Input\Filter;

/**
 * Account-wide inbox access is system-managed, never writable through record input.
 */
class MembershipPrivileges implements Filter
{
    public function filter(Data $data): void
    {
        $data->clear('globalAdmin');
    }
}

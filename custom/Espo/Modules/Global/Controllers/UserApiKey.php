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

namespace Espo\Modules\Global\Controllers;

use Espo\Core\Templates\Controllers\Base;

/**
 * Controller for the UserApiKey entity.
 *
 * Provides standard CRUD over per-user API keys. Access is gated to admins
 * by the entity's `aclDefs/UserApiKey.json` (`adminOnly: true`); the
 * controller itself only needs to wire up the default record CRUD.
 *
 * The plaintext key is surfaced in the create response and then hidden by
 * {@see \Espo\Modules\Global\Classes\Record\UserApiKey\OutputFilter} on
 * subsequent reads for non-admin users.
 */
class UserApiKey extends Base
{
}

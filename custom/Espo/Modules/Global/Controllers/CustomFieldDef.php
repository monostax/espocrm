<?php

declare(strict_types=1);

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
 * Standard CRUD controller for CustomFieldDef (schema admin entity).
 *
 * Runtime meta for record UIs lives on CustomField (gated by host-entity
 * read access). Values on Contact/Lead/etc. inherit host-entity ACL.
 */
class CustomFieldDef extends Base
{
}

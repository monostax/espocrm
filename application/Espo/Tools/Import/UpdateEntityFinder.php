<?php
/************************************************************************
 * This file is part of EspoCRM.
 *
 * EspoCRM – Open Source CRM application.
 * Copyright (C) 2014-2026 EspoCRM, Inc.
 * Website: https://www.espocrm.com
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 ************************************************************************/

namespace Espo\Tools\Import;

use Espo\Core\ORM\Entity;
use Espo\Entities\User;
use stdClass;

/**
 * Resolves an existing record for update imports with entity-specific fields.
 */
interface UpdateEntityFinder
{
    /**
     * @param array<string, mixed> $whereClause
     */
    public function find(array $whereClause, User $user, stdClass $values): ?Entity;
}

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
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 *
 * The interactive user interfaces in modified source and object code versions
 * of this program must display Appropriate Legal Notices, as required under
 * Section 5 of the GNU Affero General Public License version 3.
 *
 * In accordance with Section 7(b) of the GNU Affero General Public License version 3,
 * these Appropriate Legal Notices must retain the display of the "EspoCRM" word.
 ************************************************************************/

namespace Espo\Core\Record;

use Espo\Core\Api\Request;
use Espo\Core\Exceptions\BadRequest;

class UpdateParamsFetcher
{
    public function __construct() {}

    public function fetch(Request $request): UpdateParams
    {
        $data = $request->getParsedBody();

        $skipDuplicateCheck = $request->hasHeader('X-Skip-Duplicate-Check') ?
            strtolower($request->getHeader('X-Skip-Duplicate-Check') ?? '') === 'true' :
            $data->_skipDuplicateCheck ?? // legacy
            false;

        $versionNumber = $request->getHeader('X-Version-Number');

        if ($versionNumber !== null) {
            if (!preg_match('/\A(?:0|[1-9][0-9]*)\z/', $versionNumber) ||
                filter_var($versionNumber, FILTER_VALIDATE_INT) === false
            ) {
                throw new BadRequest('Invalid version number.');
            }

            $versionNumber = (int) $versionNumber;
        }

        return UpdateParams::create()
            ->withRequiredVersion()
            ->withSkipDuplicateCheck($skipDuplicateCheck)
            ->withVersionNumber($versionNumber);
    }
}

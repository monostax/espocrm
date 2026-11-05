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

namespace Espo\Core\Utils\Config;

use Espo\Core\Utils\File\Manager as FileManager;

/**
 * The file manager config files are saved with. Its temp files live beside the config, in `data`, so a save
 * is a rename on the same filesystem and replaces the file whole. In Kubernetes `data/tmp` is a per-pod
 * volume while `data` is shared by every pod; a rename across them is a copy, so a save became an in-place
 * write, and two pods writing at once left a shorter file over a longer one's tail (the CRM went down on
 * 2026-11-05 with a stray `'cacheTimestamp' => …` after the closing `];`).
 */
class ConfigTempFileManager extends FileManager
{
    protected string $tmpDir = 'data/config-tmp';
}

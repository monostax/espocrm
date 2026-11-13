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

namespace Espo\Core\Job;

use Espo\Core\Utils\File\Manager as FileManager;
use RuntimeException;

/**
 * @internal
 */
class CronUtil
{
    protected string $lastRunTimeFile = 'data/cache/application/cronLastRunTime.php';

    public function __construct(
        private FileManager $fileManager,
        private ConfigDataProvider $configDataProvider,
    ) {}

    public function checkLastRunTime(): bool
    {
        $currentTime = time();
        $lastRunTime = $this->getLastRunTime();

        $cronMinInterval = $this->configDataProvider->getCronMinInterval();

        if ($currentTime > ($lastRunTime + $cronMinInterval)) {
            return true;
        }

        return false;
    }

    private function getLastRunTime(): int
    {
        if ($this->fileManager->isFile($this->lastRunTimeFile)) {
            try {
                $data = $this->fileManager->getPhpContents($this->lastRunTimeFile);
            } catch (RuntimeException) {
                $data = null;
            }

            if (is_array($data) && isset($data['time'])) {
                return (int) $data['time'];
            }
        }

        return time() - $this->configDataProvider->getCronMinInterval() - 1;
    }

    public function updateLastRunTime(): void
    {
        $data = ['time' => time()];

        $this->fileManager->putPhpContents($this->lastRunTimeFile, $data, false, true);
    }
}

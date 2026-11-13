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

namespace Espo\Classes\ConsoleCommands;

use Espo\Core\Console\Command;
use Espo\Core\Console\Command\Params;
use Espo\Core\Console\IO;
use Espo\Core\Job\Exceptions\TooFrequentRun;
use Espo\Core\Job\JobManager;
use Espo\Core\Job\PrepareProcessor;
use Espo\Core\Utils\Config\SystemConfig;
use RuntimeException;

/**
 * @noinspection PhpUnused
 */
class CronRun implements Command
{
    public function __construct(
        private PrepareProcessor $prepareProcessor,
        private JobManager $jobManager,
        private SystemConfig $config,
    ) {}

    public function run(Params $params, IO $io): void
    {
        if (!$this->config->isCronEnabled()) {
            throw new RuntimeException("Cron cannot be run as 'cronDisabled' is set to true in the config.");
        }

        if (!$params->hasFlag('force') && $this->config->isMaintenanceMode()) {
            throw new RuntimeException("Cron cannot be run in maintenance mode. You can use --force flag.");
        }

        try {
            $this->prepareProcessor->process();
        } catch (TooFrequentRun $e) {
            throw new RuntimeException('Too frequent run.', previous: $e);
        }

        $this->jobManager->processMainQueue();
    }
}

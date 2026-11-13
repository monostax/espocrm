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

namespace Espo\Core\Job\QueueProcessor\QueueProcessors;

use Espo\Core\Job\AsyncPoolFactory;
use Espo\Core\Job\ConfigDataProvider;
use Espo\Core\Job\QueueProcessor;
use Espo\Core\Utils\Log;
use Espo\Core\Job\QueueProcessor\Params;

/**
 * @internal
 */
class DefaultQueueProcessor implements QueueProcessor
{
    public function __construct(
        private ProcessPoolQueueProcessor $processPoolQueueProcessor,
        private SequentialQueueProcessor $sequentialQueueProcessor,
        private AsyncPoolFactory $asyncPoolFactory,
        private Log $log,
        private ConfigDataProvider $configDataProvider,
    ) {}

    public function process(Params $params): void
    {
        if ($this->inParallel()) {
            $this->processPoolQueueProcessor->process($params);
        } else {
            $this->sequentialQueueProcessor->process($params);
        }
    }

    private function inParallel(): bool
    {
        if (!$this->configDataProvider->runInParallel()) {
            return false;
        }

        if (!$this->asyncPoolFactory->isSupported()) {
            $this->log->warning("Enabled `jobRunInParallel` parameter requires pcntl and posix extensions.");

            return false;
        }

        return true;
    }
}

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

use Espo\Core\Job\Job\Status;
use Espo\Core\Job\JobRunner;
use Espo\Core\Job\QueueProcessor;
use Espo\Core\Job\QueueProcessor\Params;
use Espo\Core\Job\QueueProcessor\Picker;
use Espo\Core\Job\QueueUtil;
use Espo\Core\ORM\EntityManager;
use Espo\Core\Utils\System;
use Espo\Entities\Job;

class SequentialQueueProcessor implements QueueProcessor
{
    public function __construct(
        private QueueUtil $queueUtil,
        private JobRunner $jobRunner,
        private EntityManager $entityManager,
        private Picker $picker,
    ) {}

    public function process(Params $params): void
    {
        foreach ($this->picker->pick($params) as $job) {
            $this->processJob($job);
        }
    }

    private function processJob(Job $job): void
    {
        if ($this->toSkip($job)) {
            return;
        }

        $this->prepareJob($job);

        $this->jobRunner->run($job);
    }

    private function toSkip(Job $job): bool
    {
        return $this->queueUtil->isScheduledJobRunning($job);
    }

    private function prepareJob(Job $job): void
    {
        $job
            ->setStartedAtNow()
            ->setStatus(Status::RUNNING)
            ->setPid(System::getPid());

        $this->entityManager->saveEntity($job);
    }
}

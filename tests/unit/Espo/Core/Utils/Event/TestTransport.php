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

namespace tests\unit\Espo\Core\Utils\Event;

use Closure;
use Espo\Core\Utils\Event\Envelope;
use Espo\Core\Utils\Event\EventTransport;
use stdClass;

class TestTransport implements EventTransport
{
    /**
     * @var (Closure(Envelope): void)|null
     */
    private ?Closure $callback = null;

    /**
     * @param Closure(Envelope): void $callback
     */
    public function subscribe(Closure $callback): void
    {
        $this->callback = $callback;
    }

    public function publish(Envelope $envelope): void
    {}

    public function tick(): void
    {}

    public function dispatchForTest(string $eventClassName, stdClass $payload): void
    {
        if (!$this->callback) {
            return;
        }

        $envelope = new Envelope(
            eventClassName: $eventClassName,
            payload: $payload,
            origin: 'other',
        );

        ($this->callback)($envelope);
    }
}

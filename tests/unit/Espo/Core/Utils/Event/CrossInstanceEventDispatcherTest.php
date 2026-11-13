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

use Espo\Core\Utils\Event\Context;
use Espo\Core\Utils\Event\CrossInstanceEventDispatcher;
use Espo\Core\Utils\Event\OriginProvider;
use PHPUnit\Framework\TestCase;

class CrossInstanceEventDispatcherTest extends TestCase
{
    public function testDispatching(): void
    {
        $originProvider = $this->getOriginProvider();

        $transport = new TestTransport();

        $dispatcher = new CrossInstanceEventDispatcher(
            transport: $transport,
            originProvider: $originProvider,
        );

        $value = false;

        $callback1 = function (TestCiEvent1 $event, Context $context) use (&$value) {
            if (!$context->isLocal && $event->value === 'hello') {
                $value = true;
            }
        };

        $callback2 = function () use (&$value) {
            $value = false;
        };

        $dispatcher->subscribe(TestCiEvent1::class, $callback1);
        $dispatcher->subscribe(TestCiEvent2::class, $callback2);

        $transport->dispatchForTest(TestCiEvent1::class, (object) ['value' => 'hello']);

        $this->assertTrue($value);
    }

    private function getOriginProvider(): OriginProvider
    {
        $originProvider = $this->createMock(OriginProvider::class);

        $originProvider->method('get')
            ->willReturn('test-id');

        return $originProvider;
    }
}

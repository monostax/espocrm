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

namespace tests\unit\Espo\Core\Utils\Security;

use Espo\Core\Utils\Security\HostCheck;
use Espo\Core\Utils\Security\UrlCheck;
use PHPUnit\Framework\TestCase;

class UrlCheckTest extends TestCase
{
    public function testGetCurlResolveDual(): void
    {
        $hostCheck = $this->createMock(HostCheck::class);

        $urlCheck = new UrlCheck($hostCheck);

        $url = 'https://test.com';

        $hostCheck->method('isDomainHost')
            ->with('test.com')
            ->willReturn(true);

        $hostCheck->method('getHostIpAddresses')
            ->with('test.com')
            ->willReturn([
                '10.0.0.1',
                '10.0.0.2',
                '2001:0db8:85a3:0000:0000:8a2e:0370:7334',
            ]);

        $this->assertEquals([
            'test.com:443:10.0.0.1',
            'test.com:443:10.0.0.2',
        ], $urlCheck->getCurlResolve($url));
    }

    public function testGetCurlResolveIpV6Only(): void
    {
        $hostCheck = $this->createMock(HostCheck::class);

        $urlCheck = new UrlCheck($hostCheck);

        $url = 'https://test.com';

        $hostCheck->method('isDomainHost')
            ->with('test.com')
            ->willReturn(true);

        $hostCheck->method('getHostIpAddresses')
            ->with('test.com')
            ->willReturn([
                '2001:0db8:85a3:0000:0000:8a2e:0370:7334',
            ]);

        $this->assertEquals([
            'test.com:443:[2001:0db8:85a3:0000:0000:8a2e:0370:7334]',
        ], $urlCheck->getCurlResolve($url));
    }
}

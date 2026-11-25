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

namespace tests\unit\Espo\Tools\OAuthServer;

use Espo\Tools\OAuthServer\DefaultScopeApplier;
use Espo\Tools\OAuthServer\Entities\Client;
use Espo\Tools\OAuthServer\Repository\ClientRepository;
use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;

class DefaultScopeApplierTest extends TestCase
{
    private const string CLIENT_ID = 'cffc8a9b-5bfc-4955-8534-e0c5c265435e';

    public function testNoScopeUsesClientScopes(): void
    {
        $applier = new DefaultScopeApplier($this->repository(['Account', 'Mcp']));

        $request = $applier->apply($this->request(['client_id' => self::CLIENT_ID, 'state' => 'x']));

        $this->assertSame('Account Mcp', $request->getQueryParams()['scope']);
        $this->assertSame('x', $request->getQueryParams()['state']);
    }

    public function testBlankScopeUsesClientScopes(): void
    {
        $applier = new DefaultScopeApplier($this->repository(['Global', 'Mcp']));

        $request = $applier->apply($this->request(['client_id' => self::CLIENT_ID, 'scope' => '  ']));

        $this->assertSame('Global Mcp', $request->getQueryParams()['scope']);
    }

    public function testRequestedScopeIsKept(): void
    {
        $repository = $this->createMock(ClientRepository::class);
        $repository->expects($this->never())->method('getActiveByIdentifier');

        $original = $this->request(['client_id' => self::CLIENT_ID, 'scope' => 'Mcp']);

        $this->assertSame($original, (new DefaultScopeApplier($repository))->apply($original));
    }

    public function testUnknownClientLeavesRequest(): void
    {
        $repository = $this->createMock(ClientRepository::class);
        $repository->method('getActiveByIdentifier')->willReturn(null);

        $original = $this->request(['client_id' => 'nope']);

        $this->assertSame($original, (new DefaultScopeApplier($repository))->apply($original));
    }

    public function testNoClientIdLeavesRequest(): void
    {
        $repository = $this->createMock(ClientRepository::class);
        $repository->expects($this->never())->method('getActiveByIdentifier');

        $original = $this->request(['response_type' => 'code']);

        $this->assertSame($original, (new DefaultScopeApplier($repository))->apply($original));
    }

    /**
     * @param string[] $scopes
     */
    private function repository(array $scopes): ClientRepository
    {
        $client = $this->createMock(Client::class);
        $client->method('getScopes')->willReturn($scopes);

        $repository = $this->createMock(ClientRepository::class);
        $repository->method('getActiveByIdentifier')->with(self::CLIENT_ID)->willReturn($client);

        return $repository;
    }

    /**
     * @param array<string, string> $params
     */
    private function request(array $params): \Psr\Http\Message\ServerRequestInterface
    {
        return (new ServerRequestFactory())
            ->createServerRequest('GET', 'https://app.monostax.ai/oauth/authorize?' . http_build_query($params))
            ->withQueryParams($params);
    }
}

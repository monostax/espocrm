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

namespace tests\unit\Espo\Modules\Mcp\Tools\Mcp;

use Espo\Core\Acl;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Entities\User;
use Espo\Modules\Mcp\Entities\Endpoint;
use Espo\Modules\Mcp\Tools\Mcp\EndpointProvider;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\RDBRelation;
use Espo\ORM\Repository\RDBRepository;
use Espo\ORM\Repository\RDBSelectBuilder;
use PHPUnit\Framework\TestCase;

class EndpointProviderTest extends TestCase
{
    public function testAllUsersEndpointSkipsMembership(): void
    {
        $endpoint = $this->endpoint(active: true, allUsers: true);

        $provider = $this->provider($endpoint, mcpScope: true, related: null);

        $this->assertSame($endpoint, $provider->get('monostax'));
    }

    public function testAllUsersEndpointStillNeedsMcpScope(): void
    {
        $provider = $this->provider($this->endpoint(active: true, allUsers: true), mcpScope: false, related: null);

        $this->expectException(Forbidden::class);

        $provider->get('monostax');
    }

    public function testAllUsersEndpointMustBeActive(): void
    {
        $provider = $this->provider($this->endpoint(active: false, allUsers: true), mcpScope: true, related: null);

        $this->expectException(Forbidden::class);

        $provider->get('monostax');
    }

    public function testListedEndpointNeedsMembership(): void
    {
        $provider = $this->provider($this->endpoint(active: true, allUsers: false), mcpScope: true, related: false);

        $this->expectException(Forbidden::class);

        $provider->get('teste');
    }

    public function testListedEndpointAllowsMember(): void
    {
        $endpoint = $this->endpoint(active: true, allUsers: false);

        $this->assertSame($endpoint, $this->provider($endpoint, mcpScope: true, related: true)->get('teste'));
    }

    public function testMissingEndpoint(): void
    {
        $provider = $this->provider(null, mcpScope: true, related: null);

        $this->expectException(NotFound::class);

        $provider->get('nope');
    }

    private function endpoint(bool $active, bool $allUsers): Endpoint
    {
        $endpoint = $this->createMock(Endpoint::class);
        $endpoint->method('isActive')->willReturn($active);
        $endpoint->method('isForAllUsers')->willReturn($allUsers);

        return $endpoint;
    }

    /**
     * @param ?bool $related null = membership must not be looked up
     */
    private function provider(?Endpoint $endpoint, bool $mcpScope, ?bool $related): EndpointProvider
    {
        $user = $this->createMock(User::class);

        $acl = $this->createMock(Acl::class);
        $acl->method('checkScope')->with('Mcp')->willReturn($mcpScope);

        $builder = $this->createMock(RDBSelectBuilder::class);
        $builder->method('findOne')->willReturn($endpoint);

        $repository = $this->createMock(RDBRepository::class);
        $repository->method('where')->willReturn($builder);

        $entityManager = $this->createMock(EntityManager::class);
        $entityManager->method('getRDBRepositoryByClass')->with(Endpoint::class)->willReturn($repository);

        if ($related === null) {
            $entityManager->expects($this->never())->method('getRelation');
        } else {
            $relation = $this->createMock(RDBRelation::class);
            $relation->method('isRelated')->with($user)->willReturn($related);

            $entityManager->method('getRelation')->with($endpoint, Endpoint::LINK_USERS)->willReturn($relation);
        }

        return new EndpointProvider($entityManager, $user, $acl);
    }
}

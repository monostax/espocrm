<?php

declare(strict_types=1);

/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

namespace tests\unit\Espo\Modules\FeatureMcp;

use Espo\Core\Acl;
use Espo\Core\Utils\Config\ApplicationConfig;
use Espo\Entities\User;
use Espo\Modules\FeatureMcp\Tools\Setup\ConnectionSetupService;
use Espo\Modules\Mcp\Entities\Endpoint;
use Espo\ORM\EntityCollection;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\RDBRelation;
use Espo\ORM\Repository\RDBRepository;
use Espo\ORM\Repository\RDBSelectBuilder;
use Espo\Tools\OAuthServer\ClientType;
use Espo\Tools\OAuthServer\Entities\Client;
use PHPUnit\Framework\TestCase;

class ConnectionSetupServiceTest extends TestCase
{
    public function testListsUsableEndpointsAndPublicMcpClients(): void
    {
        $everyone = $this->endpoint('Monostax', 'monostax', allUsers: true);
        $linked = $this->endpoint('Vendas', 'vendas', allUsers: false);
        $other = $this->endpoint('Interno', 'interno', allUsers: false);

        $data = $this->service(
            endpoints: [$everyone, $linked, $other],
            linked: [$linked],
            clients: [
                $this->client('Claude', 'c-claude', ClientType::Public, ['Account', 'Mcp'], ['https://claude.ai/api/mcp/auth_callback']),
                $this->client('Server app', 'c-conf', ClientType::Confidential, ['Global', 'Mcp'], []),
                $this->client('Reports', 'c-reports', ClientType::Public, ['Account'], []),
            ],
        )->get();

        $this->assertTrue($data->available);
        $this->assertSame('u1', $data->user->id);
        $this->assertSame(['monostax', 'vendas'], array_map(fn ($it) => $it->slug, $data->endpoints));
        $this->assertSame('https://app.monostax.ai/api/v1/Mcp/monostax', $data->endpoints[0]->url);
        $this->assertSame(['c-claude'], array_map(fn ($it) => $it->clientId, $data->clients));
        $this->assertSame(['https://claude.ai/api/mcp/auth_callback'], $data->clients[0]->redirectUris);
    }

    public function testWithoutMcpScopeNothingIsListed(): void
    {
        $data = $this->service(
            endpoints: [$this->endpoint('Monostax', 'monostax', allUsers: true)],
            linked: [],
            clients: [$this->client('Claude', 'c-claude', ClientType::Public, ['Mcp'], [])],
            mcpScope: false,
        )->get();

        $this->assertFalse($data->available);
        $this->assertSame([], $data->endpoints);
        $this->assertSame([], $data->clients);
        $this->assertSame('u1', $data->user->id);
    }

    public function testPortalUserIsNotAvailable(): void
    {
        $data = $this->service(endpoints: [], linked: [], clients: [], userType: User::TYPE_PORTAL)->get();

        $this->assertFalse($data->available);
    }

    private function endpoint(string $name, string $slug, bool $allUsers): Endpoint
    {
        $endpoint = $this->createMock(Endpoint::class);
        $endpoint->method('getName')->willReturn($name);
        $endpoint->method('getSlug')->willReturn($slug);
        $endpoint->method('isForAllUsers')->willReturn($allUsers);
        $endpoint->method('getPublicDescription')->willReturn(null);

        return $endpoint;
    }

    /**
     * @param string[] $scopes
     * @param string[] $redirectUris
     */
    private function client(string $name, string $id, ClientType $type, array $scopes, array $redirectUris): Client
    {
        $client = $this->createMock(Client::class);
        $client->method('getName')->willReturn($name);
        $client->method('getIdentifier')->willReturn($id);
        $client->method('getClientType')->willReturn($type);
        $client->method('getScopes')->willReturn($scopes);
        $client->method('getRedirectUris')->willReturn($redirectUris);

        return $client;
    }

    /**
     * @param Endpoint[] $endpoints
     * @param Endpoint[] $linked
     * @param Client[] $clients
     */
    private function service(
        array $endpoints,
        array $linked,
        array $clients,
        bool $mcpScope = true,
        string $userType = User::TYPE_REGULAR,
    ): ConnectionSetupService {

        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn('u1');
        $user->method('getName')->willReturn('Ana');
        $user->method('getUserName')->willReturn('ana');
        $user->method('getType')->willReturn($userType);
        $user->method('isRegular')->willReturn($userType === User::TYPE_REGULAR);

        $acl = $this->createMock(Acl::class);
        $acl->method('checkScope')->with('Mcp')->willReturn($mcpScope);

        $entityManager = $this->createMock(EntityManager::class);
        $entityManager->method('getRDBRepositoryByClass')->willReturnCallback(
            fn (string $class) => $this->repository($class === Endpoint::class ? $endpoints : $clients)
        );
        $entityManager->method('getRelation')->willReturnCallback(function ($endpoint) use ($linked, $user) {
            $relation = $this->createMock(RDBRelation::class);
            $relation->method('isRelated')->with($user)->willReturn(in_array($endpoint, $linked, true));

            return $relation;
        });

        $config = $this->createMock(ApplicationConfig::class);
        $config->method('getSiteUrl')->willReturn('https://app.monostax.ai/');

        return new ConnectionSetupService($entityManager, $acl, $user, $config);
    }

    /**
     * @param object[] $items
     */
    private function repository(array $items): RDBRepository
    {
        $builder = $this->createMock(RDBSelectBuilder::class);
        $builder->method('order')->willReturnSelf();
        $builder->method('find')->willReturn(new EntityCollection($items));

        $repository = $this->createMock(RDBRepository::class);
        $repository->method('where')->willReturn($builder);

        return $repository;
    }
}

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

namespace Espo\Modules\Global\Tools\Mcp;

use Espo\Core\Acl;
use Espo\Core\Utils\Config\ApplicationConfig;
use Espo\Entities\User;
use Espo\Modules\Mcp\Entities\Endpoint;
use Espo\Modules\Mcp\Tools\Mcp\Scope;
use Espo\ORM\EntityManager;
use Espo\Tools\OAuthServer\ClientType;
use Espo\Tools\OAuthServer\Entities\Client;
use stdClass;

/**
 * What the signed-in user needs to connect an AI app to the CRM's MCP server: the endpoints they
 * may use and the public OAuth clients that grant the `Mcp` scope. MCP endpoints and OAuth clients
 * are admin-only records, so the Chatwoot settings page reads this instead of them.
 *
 * Nothing secret leaves: public clients have no secret, and a client ID only starts a sign-in the
 * user still approves. Endpoints follow the same gate as EndpointProvider (active, and All Users
 * or the user is linked), so the page never offers one that would refuse the user.
 */
class ConnectionSetupService
{
    private const string API_PATH = '/api/v1/Mcp/';

    public function __construct(
        private EntityManager $entityManager,
        private Acl $acl,
        private User $user,
        private ApplicationConfig $applicationConfig,
    ) {}

    public function get(): stdClass
    {
        $available = ($this->user->isRegular() || $this->user->getType() === User::TYPE_ADMIN) &&
            $this->acl->checkScope(Scope::MCP);

        return (object) [
            'available' => $available,
            'user' => (object) [
                'id' => $this->user->getId(),
                'name' => $this->user->getName() ?? $this->user->getUserName(),
                'userName' => $this->user->getUserName(),
            ],
            'endpoints' => $available ? $this->getEndpoints() : [],
            'clients' => $available ? $this->getClients() : [],
        ];
    }

    /**
     * @return stdClass[]
     */
    private function getEndpoints(): array
    {
        $endpoints = $this->entityManager
            ->getRDBRepositoryByClass(Endpoint::class)
            ->where([Endpoint::FIELD_STATUS => Endpoint::STATUS_ACTIVE])
            ->order('name')
            ->find();

        $siteUrl = rtrim($this->applicationConfig->getSiteUrl(), '/');
        $list = [];

        foreach ($endpoints as $endpoint) {
            if (!$endpoint->isForAllUsers() && !$this->isLinked($endpoint)) {
                continue;
            }

            $list[] = (object) [
                'name' => $endpoint->getName(),
                'slug' => $endpoint->getSlug(),
                'url' => $siteUrl . self::API_PATH . $endpoint->getSlug(),
                'description' => $endpoint->getPublicDescription(),
            ];
        }

        return $list;
    }

    private function isLinked(Endpoint $endpoint): bool
    {
        return $this->entityManager
            ->getRelation($endpoint, Endpoint::LINK_USERS)
            ->isRelated($this->user);
    }

    /**
     * @return stdClass[]
     */
    private function getClients(): array
    {
        $clients = $this->entityManager
            ->getRDBRepositoryByClass(Client::class)
            ->where([Client::FIELD_STATUS => Client::STATUS_ACTIVE])
            ->order('name')
            ->find();

        $list = [];

        foreach ($clients as $client) {
            if (
                $client->getClientType() !== ClientType::Public ||
                !in_array(Scope::MCP, $client->getScopes(), true) ||
                !$client->getIdentifier()
            ) {
                continue;
            }

            $list[] = (object) [
                'name' => $client->getName() ?? $client->getIdentifier(),
                'clientId' => $client->getIdentifier(),
                'redirectUris' => $client->getRedirectUris(),
            ];
        }

        return $list;
    }
}

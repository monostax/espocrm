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

namespace tests\unit\Espo\Modules\FeatureMcp\Chat;

use Espo\Core\Exceptions\Forbidden;
use Espo\Core\ORM\Entity;
use Espo\Entities\User;
use Espo\Modules\Chatwoot\Services\ChatwootApiClient;
use Espo\Modules\FeatureMcp\Tools\Chat\ChatIdentity;
use Espo\ORM\EntityCollection;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\RDBRepository;
use Espo\ORM\Repository\RDBSelectBuilder;
use PHPUnit\Framework\TestCase;

class ChatIdentityTest extends TestCase
{
    public function testVerifiedIdentityActs(): void
    {
        $api = $this->api(['id' => 5, 'accounts' => [['id' => 6, 'status' => 'active']]]);
        $access = $this->identity([['cu1', 5, 'tok']], $api)->access(6);

        $this->assertSame(6, $access->accountId);
        $this->assertSame('Acme', $access->workspace);
        $this->assertSame('tok', $access->token);
    }

    public function testProfileOfAnotherUserIsRefused(): void
    {
        $this->expectException(Forbidden::class);

        $this->identity([['cu1', 5, 'tok']], $this->api(['id' => 9, 'accounts' => [['id' => 6, 'status' => 'active']]]))->access(6);
    }

    public function testInactiveMembershipIsRefused(): void
    {
        $this->expectException(Forbidden::class);

        $this->identity([['cu1', 5, 'tok']], $this->api(['id' => 5, 'accounts' => [['id' => 6, 'status' => 'inactive']]]))->access(6);
    }

    public function testTwoIdentitiesInOneWorkspaceAreAmbiguous(): void
    {
        $identity = $this->identity([['cu1', 5, 'tok'], ['cu2', 8, 'tok2']], $this->api([]));

        $this->assertSame([], $identity->workspaces());
    }

    public function testMissingTokenIsFetchedWithThePlatformToken(): void
    {
        $api = $this->api(['id' => 5, 'accounts' => [['id' => 6, 'status' => 'active']]]);
        $api->expects($this->once())->method('fetchUserAccessToken')->with('http://chat', 'platform-token', 5)->willReturn('fetched');

        $this->assertSame('fetched', $this->identity([['cu1', 5, '']], $api)->access(null)->token);
    }

    /** @param array<string, mixed> $profile */
    private function api(array $profile): ChatwootApiClient
    {
        $api = $this->createMock(ChatwootApiClient::class);
        $api->method('accountRequest')->willReturn(['code' => 200, 'body' => $profile]);

        return $api;
    }

    /** @param list<array{string, int, string}> $chatUsers id, Chat user id, token */
    private function identity(array $chatUsers, ChatwootApiClient $api): ChatIdentity
    {
        $entity = function (string $id, array $values): Entity {
            $mock = $this->createMock(Entity::class);
            $mock->method('getId')->willReturn($id);
            $mock->method('get')->willReturnCallback(fn ($key) => $values[$key] ?? null);

            return $mock;
        };

        $users = [];
        $byId = [
            'acc1' => $entity('acc1', ['chatwootAccountId' => 6, 'name' => 'Acme', 'platformId' => 'p1']),
            'p1' => $entity('p1', ['backendUrl' => 'http://chat', 'accessToken' => 'platform-token']),
        ];

        foreach ($chatUsers as [$id, $chatId, $token]) {
            $users[] = $byId[$id] = $entity($id, ['platformId' => 'p1', 'chatwootUserId' => $chatId, 'userAccessToken' => $token]);
        }

        $em = $this->createMock(EntityManager::class);
        $em->method('getEntityById')->willReturnCallback(fn ($type, $id) => $byId[$id] ?? null);
        $em->method('getRDBRepository')->willReturnCallback(function (string $type) use ($users, $entity) {
            $builder = $this->createMock(RDBSelectBuilder::class);
            $builder->method('find')->willReturn(new EntityCollection(
                $type === 'ChatwootUser' ? $users : [$entity('m1', ['chatwootAccountId' => 'acc1'])]
            ));
            $repository = $this->createMock(RDBRepository::class);
            $repository->method('where')->willReturn($builder);

            return $repository;
        });

        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn('crm-user');

        return new ChatIdentity($em, $user, $api);
    }
}

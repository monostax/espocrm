<?php

namespace tests\unit\Espo\Modules\Chatwoot\Services;

use Espo\Core\Utils\Log;
use Espo\Modules\Chatwoot\Services\ChatwootApiClient;
use Espo\Modules\Chatwoot\Services\ChatwootIntegrationUserAccess;
use Espo\Modules\Chatwoot\Services\IntegrationUserNameResolver;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use PHPUnit\Framework\TestCase;

class ChatwootIntegrationUserAccessTest extends TestCase
{
    public function testEnsuresDirectNonAssignableInboxAccess(): void
    {
        $account = $this->createMock(Entity::class);
        $account->method('get')->willReturnMap([
            ['conciergeUserId', 'local-user'],
            ['chatwootAccountId', 6],
            ['apiKey', 'user-token'],
            ['platformId', 'platform'],
        ]);

        $user = $this->createMock(Entity::class);
        $user->method('get')->willReturnMap([
            ['chatwootUserId', 41],
            ['name', '✦ Concierge (Monostax)'],
            ['displayName', '✦ Concierge (Monostax)'],
        ]);
        $user->expects($this->exactly(2))->method('set');

        $platform = $this->createMock(Entity::class);
        $platform->method('get')->willReturnMap([
            ['backendUrl', 'http://chatwoot.local'],
            ['accessToken', 'platform-token'],
        ]);

        $entityManager = $this->createMock(EntityManager::class);
        $entityManager->method('getEntityById')->willReturnMap([
            ['ChatwootUser', 'local-user', $user],
            ['ChatwootPlatform', 'platform', $platform],
        ]);

        $apiClient = $this->createMock(ChatwootApiClient::class);
        $nameResolver = $this->createMock(IntegrationUserNameResolver::class);
        $nameResolver->method('resolve')->with($account)->willReturn('Monostax Integração');
        $apiClient->expects($this->once())->method('updateUser')->with(
            'http://chatwoot.local',
            'platform-token',
            41,
            ['name' => 'Monostax Integração', 'display_name' => 'Monostax Integração']
        );
        $apiClient->expects($this->once())->method('attachUserToAccount')->with(
            'http://chatwoot.local',
            'platform-token',
            6,
            41,
            'administrator',
            true,
            false
        );
        $apiClient->expects($this->once())->method('addInboxMembers')->with(
            'http://chatwoot.local',
            'user-token',
            6,
            65,
            [41]
        );
        $apiClient->expects($this->once())->method('updateInboxMemberSettings')->with(
            'http://chatwoot.local',
            'user-token',
            6,
            65,
            41,
            ['assignable' => false]
        );
        $entityManager->expects($this->once())->method('saveEntity')->with(
            $user,
            ['silent' => true]
        );

        $service = new ChatwootIntegrationUserAccess(
            $entityManager,
            $apiClient,
            $nameResolver,
            $this->createMock(Log::class)
        );

        $service->ensureInboxAccess($account, 65);
    }
}

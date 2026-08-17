<?php

namespace tests\unit\Espo\Modules\Chatwoot\Services;

use Espo\Core\Utils\Log;
use Espo\Modules\Chatwoot\Services\ChatwootIntegrationUserAccess;
use Espo\Modules\Chatwoot\Services\ChatwootWahaAppTokenSync;
use Espo\Modules\Chatwoot\Services\WahaApiClient;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class ChatwootWahaAppTokenSyncTest extends TestCase
{
    public function testEnsuresInboxAccessEvenWhenTokenIsAlreadyCurrent(): void
    {
        $account = $this->createMock(Entity::class);
        $integration = $this->createMock(Entity::class);
        $integration->method('getId')->willReturn('integration-1');
        $integration->method('get')->willReturnMap([
            ['wahaAppId', 'app-1'],
            ['wahaPlatformId', 'waha-platform'],
        ]);

        $wahaPlatform = $this->createMock(Entity::class);
        $wahaPlatform->method('get')->willReturnMap([
            ['backendUrl', 'http://waha.local'],
            ['apiKey', 'waha-token'],
        ]);

        $entityManager = $this->createMock(EntityManager::class);
        $entityManager->method('getEntityById')
            ->with('WahaPlatform', 'waha-platform')
            ->willReturn($wahaPlatform);

        $wahaApi = $this->createMock(WahaApiClient::class);
        $wahaApi->method('getApp')->willReturn([
            'id' => 'app-1',
            'app' => 'chatwoot',
            'config' => [
                'accountToken' => 'current-token',
                'editMessage' => 'ON',
                'inboxId' => 65,
            ],
        ]);
        $wahaApi->expects($this->never())->method('updateApp');

        $access = $this->createMock(ChatwootIntegrationUserAccess::class);
        $access->expects($this->once())->method('ensureInboxAccess')->with($account, 65);

        $service = new ChatwootWahaAppTokenSync(
            $entityManager,
            $wahaApi,
            $access,
            $this->createMock(Log::class)
        );

        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('syncIntegration');
        $method->setAccessible(true);

        $this->assertSame(
            'skipped',
            $method->invoke($service, $account, $integration, 'current-token')
        );
    }
}

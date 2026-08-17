<?php

namespace tests\unit\Espo\Modules\Chatwoot\Services;

use Espo\Core\Acl;
use Espo\Core\Utils\Log;
use Espo\Modules\Chatwoot\Services\ChatwootIntegrationUserAccess;
use Espo\Modules\Chatwoot\Services\WahaApiClient;
use Espo\Modules\Chatwoot\Services\WahaSessionApp;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\Repository\RDBRepository;
use Espo\ORM\Repository\RDBSelectBuilder;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class WahaSessionAppAccessTest extends TestCase
{
    public function testEnsuresAccessForCrmManagedAccountToken(): void
    {
        $account = $this->createMock(Entity::class);
        $account->method('get')->with('apiKey')->willReturn('account-token');

        $select = $this->createMock(RDBSelectBuilder::class);
        $select->method('findOne')->willReturn($account);
        $repository = $this->createMock(RDBRepository::class);
        $repository->method('where')->willReturn($select);

        $entityManager = $this->createMock(EntityManager::class);
        $entityManager->method('getRDBRepository')
            ->with('ChatwootAccount')
            ->willReturn($repository);

        $access = $this->createMock(ChatwootIntegrationUserAccess::class);
        $access->expects($this->once())->method('ensureInboxAccess')->with($account, 65);

        $service = new WahaSessionApp(
            $entityManager,
            $this->createMock(WahaApiClient::class),
            $access,
            $this->createMock(Log::class),
            $this->createMock(Acl::class)
        );

        $reflection = new ReflectionClass($service);
        $method = $reflection->getMethod('ensureChatwootAppAccess');
        $method->setAccessible(true);
        $method->invoke($service, [
            'accountId' => 6,
            'accountToken' => 'account-token',
            'inboxId' => 65,
        ]);
    }
}

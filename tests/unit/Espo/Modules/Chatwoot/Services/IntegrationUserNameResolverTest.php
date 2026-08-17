<?php

namespace tests\unit\Espo\Modules\Chatwoot\Services;

use Espo\Core\Utils\Config;
use Espo\Modules\Chatwoot\Services\IntegrationUserNameResolver;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use PHPUnit\Framework\TestCase;

class IntegrationUserNameResolverTest extends TestCase
{
    public function testUsesTenantLanguage(): void
    {
        $account = $this->createMock(Entity::class);
        $account->method('get')->with('tenantId')->willReturn('tenant-1');

        $tenant = $this->createMock(Entity::class);
        $tenant->method('get')->with('language')->willReturn('pt_BR');

        $entityManager = $this->createMock(EntityManager::class);
        $entityManager->method('getEntityById')->with('Tenant', 'tenant-1')->willReturn($tenant);

        $resolver = new IntegrationUserNameResolver(
            $entityManager,
            $this->createMock(Config::class)
        );

        $this->assertSame('Monostax Integração', $resolver->resolve($account));
    }

    public function testDefaultsToEnglishForNonPortugueseLocale(): void
    {
        $config = $this->createMock(Config::class);
        $config->method('get')->with('language')->willReturn('en_US');

        $resolver = new IntegrationUserNameResolver(
            $this->createMock(EntityManager::class),
            $config
        );

        $this->assertSame('Monostax Integration', $resolver->resolve(null));
    }
}

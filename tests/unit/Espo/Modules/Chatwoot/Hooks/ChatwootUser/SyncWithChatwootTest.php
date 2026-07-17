<?php
/************************************************************************
 * This file is part of Monostax.
 *
 * Monostax – Custom EspoCRM extensions.
 * Copyright (C) 2025 Antonio Moura. All rights reserved.
 * Website: https://www.monostax.ai
 *
 * PROPRIETARY AND CONFIDENTIAL
 ************************************************************************/

namespace tests\unit\Espo\Modules\Chatwoot\Hooks\ChatwootUser;

use Espo\Modules\Chatwoot\Hooks\ChatwootUser\SyncWithChatwoot;
use Espo\ORM\Entity;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class SyncWithChatwootTest extends TestCase
{
    public function testMapsLocalEmailAddressToChatwootEmailPayload(): void
    {
        $entity = $this->createMock(Entity::class);
        $entity->method('get')->willReturnMap([
            ['name', 'Test User'],
            ['emailAddress', 'user@example.com'],
            ['password', 'secret-password'],
            ['displayName', 'Display Name'],
        ]);

        $reflection = new ReflectionClass(SyncWithChatwoot::class);
        $service = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('prepareUserData');
        $method->setAccessible(true);

        $result = $method->invoke($service, $entity);

        $this->assertSame('user@example.com', $result['email']);
        $this->assertArrayNotHasKey('emailAddress', $result);
        $this->assertSame('Display Name', $result['display_name']);
    }
}

<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\Global\Hooks\UserApiKey;

use Espo\Core\Exceptions\Forbidden;
use Espo\Modules\Global\Hooks\UserApiKey\LockOwner;
use Espo\ORM\Entity;
use PHPUnit\Framework\TestCase;

/**
 * A key logs in as its `user`, and edit access is checked against the stored
 * owner. Moving a key you created (so you know its secret) to someone else
 * would let you log in as them, e.g. as an admin.
 */
class LockOwnerTest extends TestCase
{
    private function key(bool $isNew, string $changed): Entity
    {
        $key = $this->createMock(Entity::class);
        $key->method('isNew')->willReturn($isNew);
        $key->method('isAttributeChanged')->willReturnCallback(fn (string $name) => $name === $changed);

        return $key;
    }

    public function testMovingAKeyToAnotherUserIsRefused(): void
    {
        $this->expectException(Forbidden::class);

        (new LockOwner())->beforeSave($this->key(false, 'userId'), []);
    }

    public function testCreatingAndEditingOtherFieldsIsAllowed(): void
    {
        (new LockOwner())->beforeSave($this->key(true, 'userId'), []);
        (new LockOwner())->beforeSave($this->key(false, 'isActive'), []);

        $this->addToAssertionCount(1);
    }
}

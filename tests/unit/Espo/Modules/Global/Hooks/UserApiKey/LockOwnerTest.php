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

    /**
     * Relating a key from the User side (POST /User/{id}/userApiKeys, mass
     * link, or userApiKeysIds on update) writes userId with a plain UPDATE,
     * so LockOwner never runs. A read-only link is refused for edit for
     * everyone, admins included (AclManager::getGlobalRestrictionTypeList).
     */
    public function testKeysCannotBeRelatedToAUserFromTheUserSide(): void
    {
        $path = dirname(__DIR__, 7) . '/custom/Espo/Modules/Global/Resources/metadata/entityDefs/User.json';
        $defs = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        $this->assertTrue($defs['links']['userApiKeys']['readOnly'] ?? false);
    }
}

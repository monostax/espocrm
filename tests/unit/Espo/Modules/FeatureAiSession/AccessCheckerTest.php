<?php
declare(strict_types=1);
namespace tests\unit\Espo\Modules\FeatureAiSession;

use Espo\Core\Acl\DefaultAccessChecker;
use Espo\Core\Acl\ScopeData;
use Espo\Entities\User;
use Espo\Modules\FeatureAiSession\Classes\AccessChecker;
use Espo\Modules\FeatureAiSession\Services\Access;
use Espo\ORM\Entity;
use PHPUnit\Framework\TestCase;

class AccessCheckerTest extends TestCase
{
    public function testBroadRoleAndTenantAdminDoNotBecomeInstanceAdministrators(): void
    {
        $default = $this->createMock(DefaultAccessChecker::class);
        $default->method('checkEntityRead')->willReturn(true);
        $default->method('checkEntityStream')->willReturn(true);
        $default->method('checkEntityEdit')->willReturn(true);
        $access = $this->createMock(Access::class);
        $access->method('owner')->willReturn(false);
        $checker = new AccessChecker($default, $access);
        $actor = $this->createMock(User::class);
        $actor->method('isActive')->willReturn(true);
        $instanceAdmin = false;
        $actor->method('isAdmin')->willReturnCallback(function () use (&$instanceAdmin) { return $instanceAdmin; });
        $entity = $this->createMock(Entity::class);
        $data = $this->createMock(ScopeData::class);
        self::assertFalse($checker->checkEntityRead($actor, $entity, $data));
        self::assertFalse($checker->checkEntityStream($actor, $entity, $data));
        self::assertFalse($checker->checkEntityEdit($actor, $entity, $data));
        $instanceAdmin = true;
        self::assertTrue($checker->checkEntityRead($actor, $entity, $data));
        self::assertTrue($checker->checkEntityStream($actor, $entity, $data));
        self::assertFalse($checker->checkEntityEdit($actor, $entity, $data), 'Instance-level inspection does not transfer ownership.');
    }
}

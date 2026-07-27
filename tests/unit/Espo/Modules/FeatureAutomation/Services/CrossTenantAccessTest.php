<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureAutomation\Services;

use Espo\Core\Utils\Log;
use Espo\Entities\User;
use Espo\Modules\FeatureAutomation\Services\CrossTenantAccess;
use Espo\ORM\Entity;
use PHPUnit\Framework\TestCase;

/**
 * The cross-tenant escape hatch needs BOTH an instance-admin opt-in on the record
 * and an admin identity at run time. Either alone must stay tenant-scoped.
 */
class CrossTenantAccessTest extends TestCase
{
    private function makeAccess(): CrossTenantAccess
    {
        return new CrossTenantAccess($this->createMock(Log::class));
    }

    private function automation(mixed $flag, bool $hasAttribute = true): Entity
    {
        $automation = $this->createMock(Entity::class);
        $automation->method('hasAttribute')->willReturn($hasAttribute);
        $automation->method('get')->willReturn($flag);
        $automation->method('hasId')->willReturn(true);
        $automation->method('getId')->willReturn('automation-1');

        return $automation;
    }

    private function user(bool $isAdmin, bool $isSuperAdmin = false): User
    {
        $user = $this->createMock(User::class);
        $user->method('isAdmin')->willReturn($isAdmin);
        $user->method('isSuperAdmin')->willReturn($isSuperAdmin);
        $user->method('getId')->willReturn('user-1');

        return $user;
    }

    public function testDeniedWhenFlagOffEvenForAdmin(): void
    {
        $this->assertFalse(
            $this->makeAccess()->isAuthorized($this->automation(false), $this->user(true)),
        );
    }

    public function testDeniedWhenFieldAbsentEvenForAdmin(): void
    {
        $this->assertFalse(
            $this->makeAccess()->isAuthorized(
                $this->automation(null, hasAttribute: false),
                $this->user(true),
            ),
        );
    }

    public function testDeniedWhenFlagOnButActorIsNotAdmin(): void
    {
        // Demoting the run-as user must downgrade reach to tenant-scoped rather
        // than keep instance-wide access from a stale opt-in.
        $this->assertFalse(
            $this->makeAccess()->isAuthorized($this->automation(true), $this->user(false)),
        );
    }

    public function testAllowedWhenFlagOnAndActorIsAdmin(): void
    {
        $this->assertTrue(
            $this->makeAccess()->isAuthorized($this->automation(true), $this->user(true)),
        );
    }

    public function testAllowedWhenFlagOnAndActorIsSuperAdmin(): void
    {
        $this->assertTrue(
            $this->makeAccess()->isAuthorized(
                $this->automation(true),
                $this->user(false, isSuperAdmin: true),
            ),
        );
    }
}

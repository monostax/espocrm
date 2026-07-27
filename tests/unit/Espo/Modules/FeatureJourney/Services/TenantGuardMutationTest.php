<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureJourney\Services;

use Espo\Core\Exceptions\Error;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Log;
use Espo\Core\Utils\Metadata;
use Espo\Modules\FeatureJourney\Services\CustomFieldsBag;
use Espo\Modules\FeatureJourney\Services\TenantGuard;
use Espo\Modules\FeatureJourney\Services\TenantResolver;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use PHPUnit\Framework\TestCase;

class TenantGuardMutationTest extends TestCase
{
    private function makeGuard(?Metadata $metadata = null): TenantGuard
    {
        $metadata ??= $this->createMock(Metadata::class);

        return new TenantGuard(
            $this->createMock(EntityManager::class),
            $this->createMock(TenantResolver::class),
            $metadata,
            $this->createMock(CustomFieldsBag::class),
            $this->createMock(Config::class),
            $this->createMock(Log::class),
        );
    }

    public function testAssertHttpUrlAllowedRejectsWhenPrefixListEmpty(): void
    {
        $metadata = $this->createMock(Metadata::class);
        $metadata->method('get')->willReturnCallback(static function (array $path) {
            if ($path === ['app', 'journeyHttpRequest', 'blockedHostList']) {
                return ['localhost'];
            }
            if ($path === ['app', 'journeyHttpRequest', 'allowedUrlPrefixList']) {
                return [];
            }

            return null;
        });

        $guard = $this->makeGuard($metadata);
        $this->expectException(Error::class);
        $this->expectExceptionMessage('allowedUrlPrefixList');
        $guard->assertHttpUrlAllowed('https://hooks.example.com/x');
    }

    public function testAssertHttpUrlAllowedRejectsLocalhost(): void
    {
        $metadata = $this->createMock(Metadata::class);
        $metadata->method('get')->willReturnCallback(static function (array $path) {
            if ($path === ['app', 'journeyHttpRequest', 'blockedHostList']) {
                return ['localhost', '127.0.0.1'];
            }
            if ($path === ['app', 'journeyHttpRequest', 'allowedUrlPrefixList']) {
                return ['https://hooks.example.com/'];
            }

            return null;
        });

        $guard = $this->makeGuard($metadata);
        $this->expectException(Error::class);
        $this->expectExceptionMessage('blocked');
        $guard->assertHttpUrlAllowed('http://localhost/secret');
    }

    public function testAssertHttpUrlAllowedAcceptsPrefixMatch(): void
    {
        $metadata = $this->createMock(Metadata::class);
        $metadata->method('get')->willReturnCallback(static function (array $path) {
            if ($path === ['app', 'journeyHttpRequest', 'blockedHostList']) {
                return ['localhost'];
            }
            if ($path === ['app', 'journeyHttpRequest', 'allowedUrlPrefixList']) {
                return ['https://hooks.example.com/'];
            }

            return null;
        });

        $guard = $this->makeGuard($metadata);
        $guard->assertHttpUrlAllowed('https://hooks.example.com/journey');
        $this->assertTrue(true);
    }

    public function testAssertHttpUrlAllowedRejectsPrivateIp(): void
    {
        $metadata = $this->createMock(Metadata::class);
        $metadata->method('get')->willReturnCallback(static function (array $path) {
            if ($path === ['app', 'journeyHttpRequest', 'blockedHostList']) {
                return [];
            }
            if ($path === ['app', 'journeyHttpRequest', 'allowedUrlPrefixList']) {
                return ['http://10.0.0.5/'];
            }

            return null;
        });

        $guard = $this->makeGuard($metadata);
        $this->expectException(Error::class);
        $this->expectExceptionMessage('private');
        $guard->assertHttpUrlAllowed('http://10.0.0.5/hook');
    }

    public function testStampNewEntitySetsTenantAndTeams(): void
    {
        $tenant = $this->createMock(Entity::class);
        $tenant->method('get')->willReturnCallback(static function (string $attr) {
            return $attr === 'baseUserTeamId' ? 'team-base' : null;
        });

        $em = $this->createMock(EntityManager::class);
        $em->method('getEntityById')->willReturnCallback(static function (string $type, string $id) use ($tenant) {
            if ($type === 'Tenant' && $id === 'ten-1') {
                return $tenant;
            }

            return null;
        });
        // otherUserTeams path: force empty via exception (caught inside getTenantTeamIds).
        $em->method('getRDBRepository')->willThrowException(new \RuntimeException('no-repo'));

        $metadata = $this->createMock(Metadata::class);
        $guard = new TenantGuard(
            $em,
            $this->createMock(TenantResolver::class),
            $metadata,
            $this->createMock(CustomFieldsBag::class),
            $this->createMock(Config::class),
            $this->createMock(Log::class),
        );

        $entity = $this->createMock(Entity::class);
        $entity->method('hasAttribute')->willReturnCallback(static fn (string $a) => $a === 'tenantId' || $a === 'teamsIds');
        $entity->method('hasRelation')->willReturn(true);

        $set = [];
        $entity->method('set')->willReturnCallback(static function ($k, $v = null) use (&$set, $entity) {
            if (is_array($k)) {
                foreach ($k as $kk => $vv) {
                    $set[$kk] = $vv;
                }
            } else {
                $set[$k] = $v;
            }

            return $entity;
        });

        $guard->stampNewEntity($entity, 'ten-1', ['team-base', 'foreign-team']);

        $this->assertSame('ten-1', $set['tenantId']);
        $this->assertSame(['team-base'], $set['teamsIds']);
    }

    public function testAssertEntityTypeCreatableHonoursAllowList(): void
    {
        $metadata = $this->createMock(Metadata::class);
        $metadata->method('get')->willReturnCallback(static function (array $path) {
            if ($path === ['app', 'journeyCreateRecord', 'entityTypeList']) {
                return ['Task', 'Case'];
            }

            return null;
        });

        $guard = $this->makeGuard($metadata);
        $guard->assertEntityTypeCreatable('Task');

        $this->expectException(Error::class);
        $guard->assertEntityTypeCreatable('User');
    }
}

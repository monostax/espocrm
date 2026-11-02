<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\Global\Rebuild;

use Espo\Core\Utils\Metadata;
use Espo\Modules\Global\Classes\Utils\FeatureVerticalChecker;
use Espo\Modules\Global\Rebuild\SeedRole;
use PHPUnit\Framework\TestCase;

/**
 * Feature pack roles are markers: seeding them must not grant any access.
 */
class SeedRolePackRolesTest extends TestCase
{
    public function testOnlyPackVerticalsBecomeMarkerRolesWithoutGrants(): void
    {
        $metadata = $this->createMock(Metadata::class);
        $metadata->method('get')->with(['app', 'featureVerticals'])->willReturn([
            'b2b' => ['roleStaticId' => 'tenant-b2b'],
            'calls' => ['roleStaticId' => 'tenant-pack-calls', 'pack' => true],
            'broken' => ['pack' => true],
        ]);

        $seed = (new \ReflectionClass(SeedRole::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(SeedRole::class, 'metadata'))->setValue($seed, $metadata);

        $roles = (new \ReflectionMethod(SeedRole::class, 'getPackRoleDefinitions'))->invoke($seed);

        $this->assertCount(1, $roles);
        $this->assertSame('tenant-pack-calls', $roles[0]['staticId']);
        $this->assertSame('tenant-pack-calls', $roles[0]['name']);
        $this->assertEquals((object) [], $roles[0]['data']);
        $this->assertEquals((object) [], $roles[0]['fieldData']);
        $this->assertSame(['staticId', 'name', 'data', 'fieldData'], array_keys($roles[0]));
    }

    public function testRealPackMetadataOnlyDeclaresMarkerRoles(): void
    {
        $path = dirname(__DIR__, 6) . '/custom/Espo/Modules/Global/Resources/metadata/app/featureVerticals.json';
        $verticals = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        $packs = array_filter($verticals, fn (array $config) => !empty($config['pack']));

        $this->assertNotEmpty($packs);

        foreach ($packs as $name => $config) {
            $this->assertStringStartsWith('tenant-pack-', $config['roleStaticId'], "Pack '{$name}'");
        }

        $this->assertArrayNotHasKey('pack', $verticals['b2b']);
        $this->assertArrayNotHasKey('pack', $verticals['clinica']);
    }

    /** Role IDs are varchar(17); on 2026-11-02 six pack roles failed to insert with their full static IDs. */
    public function testEveryVerticalRoleIdFitsTheIdColumnAndStaysStable(): void
    {
        $path = dirname(__DIR__, 6) . '/custom/Espo/Modules/Global/Resources/metadata/app/featureVerticals.json';
        $verticals = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $ids = [];

        foreach ($verticals as $name => $config) {
            $id = FeatureVerticalChecker::roleIdFor($config['roleStaticId'], false);
            $this->assertLessThanOrEqual(FeatureVerticalChecker::ROLE_ID_LENGTH, strlen($id), "Vertical '{$name}'");
            $this->assertSame($id, FeatureVerticalChecker::roleIdFor($config['roleStaticId'], false));
            $ids[] = $id;
        }

        $this->assertSame(count($ids), count(array_unique($ids)));
        // Roles already in production keep their IDs.
        $this->assertSame('tenant-pack-calls', FeatureVerticalChecker::roleIdFor('tenant-pack-calls', false));
        $this->assertSame('tenant-pack-sms', FeatureVerticalChecker::roleIdFor('tenant-pack-sms', false));
        $this->assertSame('tenant-b2b', FeatureVerticalChecker::roleIdFor('tenant-b2b', false));
        $this->assertSame(substr(md5('tenant-pack-meetings'), 0, 17), FeatureVerticalChecker::roleIdFor('tenant-pack-meetings', false));
        $this->assertSame(md5('tenant-pack-sms'), FeatureVerticalChecker::roleIdFor('tenant-pack-sms', true));
    }
}

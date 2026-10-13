<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureCredits;

use Espo\Core\Acl\Table\CacheKeyProvider;
use Espo\Core\Acl\Table\DefaultTable;
use Espo\Core\Acl\Table\RoleEntityWrapper;
use Espo\Core\Acl\Table\RoleListProvider;
use Espo\Core\Utils\Config\SystemConfig;
use Espo\Core\Utils\DataCache;
use Espo\Core\Utils\Metadata;
use Espo\Entities\User;
use Espo\ORM\BaseEntity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class EnrichmentPermissionTest extends TestCase
{
    #[DataProvider('roles')]
    public function testActualAclTableConsumesExplicitRolePermission(array $levels, bool $admin, string $expected): void
    {
        $core = json_decode(file_get_contents('application/Espo/Resources/metadata/app/acl.json'), true, 512, JSON_THROW_ON_ERROR);
        $extension = json_decode(file_get_contents('custom/Espo/Modules/FeatureCredits/Resources/metadata/app/acl.json'), true, 512, JSON_THROW_ON_ERROR);
        $acl = array_replace_recursive($core, $extension);
        $acl['valuePermissionList'] = [...$core['valuePermissionList'], ...array_slice($extension['valuePermissionList'], 1)];
        $data = ['app' => ['acl' => $acl], 'scopes' => []];
        $metadata = $this->createStub(Metadata::class);
        $metadata->method('get')->willReturnCallback(static function ($path, $default = null) use ($data) {
            $value = $data;
            foreach (is_array($path) ? $path : explode('.', $path) as $key) {
                if (!is_array($value) || !array_key_exists($key, $value)) return $default;
                $value = $value[$key];
            }
            return $value;
        });
        $roles = [];
        foreach ($levels as $level) {
            $role = new BaseEntity('Role', ['attributes' => ['useEnrichmentCreditsPermission' => ['type' => 'varchar']]]);
            $role->set('useEnrichmentCreditsPermission', $level);
            $roles[] = new RoleEntityWrapper($role);
        }
        $provider = $this->createStub(RoleListProvider::class); $provider->method('get')->willReturn($roles);
        $cacheKey = $this->createStub(CacheKeyProvider::class); $cacheKey->method('get')->willReturn('enrichment-permission-test');
        $user = $this->createStub(User::class); $user->method('isFetched')->willReturn(true); $user->method('isAdmin')->willReturn($admin);
        $table = new DefaultTable($provider, $cacheKey, $user, $this->createStub(SystemConfig::class), $metadata, $this->createStub(DataCache::class));
        $this->assertSame($expected, $table->getPermissionLevel('useEnrichmentCredits'));
        $this->assertContains('exportPermission', $acl['valuePermissionList']);
    }

    public static function roles(): iterable
    {
        yield [[], false, 'no'];
        yield [['not-set'], false, 'no'];
        yield [['no'], false, 'no'];
        yield [['yes'], false, 'yes'];
        yield [['no', 'yes'], false, 'yes'];
        yield [[], true, 'yes'];
    }
}

<?php

namespace tests\unit\Espo\Modules\Global;

use Espo\Core\Acl;
use Espo\Core\Api\Request;
use Espo\Core\Exceptions\Forbidden;
use Espo\Modules\Global\Rebuild\SeedRole;
use Espo\Tools\OpenApi\Api\GetSpec;
use Espo\Tools\OpenApi\Provider;
use Espo\Tools\OpenApi\ProviderFactory;
use PHPUnit\Framework\TestCase;

class OpenApiAccessTest extends TestCase
{
    public function testDelegatedTenantRolesCanDiscoverTheApiSchema(): void
    {
        $seed = (new \ReflectionClass(SeedRole::class))->newInstanceWithoutConstructor();
        $definitions = (new \ReflectionMethod(SeedRole::class, 'getRoleDefinitions'))->invoke($seed);
        foreach (['tenant', 'tenant-admin', 'tenant-user-api'] as $id) {
            $role = array_values(array_filter($definitions, fn ($role) => $role['staticId'] === $id))[0];
            $acl = $this->createMock(Acl::class);
            $acl->expects($this->once())->method('checkScope')->with('OpenApi')
                ->willReturn($role['data']['OpenApi'] ?? false);
            $provider = $this->createMock(Provider::class);
            $provider->expects($this->once())->method('get')->willReturn('{"openapi":"3.1.0","paths":{}}');
            $factory = $this->createMock(ProviderFactory::class);
            $factory->expects($this->once())->method('create')->willReturn($provider);

            $response = (new GetSpec($acl, $factory))->process($this->createStub(Request::class));
            $this->assertSame('application/json', $response->getHeader('Content-Type'));
        }
    }

    public function testDocumentationStillRequiresItsOwnScopeGrant(): void
    {
        $acl = $this->createMock(Acl::class);
        $acl->expects($this->once())->method('checkScope')->with('OpenApi')->willReturn(false);
        $factory = $this->createMock(ProviderFactory::class);
        $factory->expects($this->never())->method('create');
        $this->expectException(Forbidden::class);
        (new GetSpec($acl, $factory))->process($this->createStub(Request::class));
    }
}

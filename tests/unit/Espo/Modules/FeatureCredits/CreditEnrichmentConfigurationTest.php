<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureCredits;

use Espo\Core\Api\Request;
use Espo\Core\Api\Response;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Entities\User;
use Espo\Modules\FeatureCredits\Accounting\Clock;
use Espo\Modules\FeatureCredits\Controllers\CreditEnrichmentConfiguration;
use Espo\Modules\FeatureCredits\Enrichment\Catalog;
use Espo\ORM\EntityManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/EnrichmentFixtures.php';

class CreditEnrichmentConfigurationTest extends TestCase
{
    private function controller(bool $admin): CreditEnrichmentConfiguration
    {
        $user = $this->createStub(User::class); $user->method('isAdmin')->willReturn($admin);
        $user->method('getId')->willReturn('admin');
        $manager = $this->createMock(EntityManager::class); $manager->expects($this->never())->method('getPDO');
        return new CreditEnrichmentConfiguration($user, new Catalog($manager, $this->createStub(Clock::class)));
    }

    #[DataProvider('actions')]
    public function testOnlyInstanceAdminCanReadOrPublish(string $action): void
    {
        $request = $this->createMock(Request::class);
        $request->expects($this->never())->method('getBodyContents');
        $request->expects($this->never())->method('getQueryParams');
        $response = $this->createMock(Response::class);
        $response->expects($this->once())->method('setHeader')->with('Cache-Control', 'private, no-store');
        $this->expectException(Forbidden::class);
        $this->controller(false)->$action($request, $response);
    }

    public static function actions(): iterable
    {
        yield ['getActionRead'];
        yield ['postActionPublish'];
    }

    public function testInvalidAndForgedCommandsFailBeforeDatabaseAccess(): void
    {
        $forged = [...EnrichmentFixtures::definition(), 'actorId' => 'other'];
        foreach (['null', '{}', '{', '[]', str_repeat(' ', 16385), json_encode($forged, JSON_THROW_ON_ERROR)] as $raw) {
            $request = $this->createStub(Request::class); $request->method('getBodyContents')->willReturn($raw);
            try {
                $this->controller(true)->postActionPublish($request, $this->createStub(Response::class));
                $this->fail('Invalid configuration accepted.');
            } catch (BadRequest) { $this->addToAssertionCount(1); }
        }
    }

    public function testInvalidReadQueriesFailBeforeDatabaseAccess(): void
    {
        foreach ([['tenantId' => 'tenant'], ['policyId' => ['x']], ['policyId' => 'alias'], ['policyId' => null]] as $query) {
            $request = $this->createStub(Request::class); $request->method('getQueryParams')->willReturn($query);
            try {
                $this->controller(true)->getActionRead($request, $this->createStub(Response::class));
                $this->fail('Invalid query accepted.');
            } catch (BadRequest) { $this->addToAssertionCount(1); }
        }
    }
}

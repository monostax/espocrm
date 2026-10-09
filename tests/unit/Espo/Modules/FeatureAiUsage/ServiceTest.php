<?php

declare(strict_types=1);

namespace tests\unit\Espo\Modules\FeatureAiUsage;

use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Utils\Config;
use Espo\Core\Currency\ConfigDataProvider;
use Espo\Core\Acl;
use Espo\Core\Select\SelectBuilderFactory;
use Espo\Entities\User;
use Espo\Modules\Chatwoot\Tools\Billing\TenantRateLookup;
use Espo\Modules\FeatureAiUsage\Services\{Access, Dataset, Ledger, Projection, Service};
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\ORM\EntityCollection;
use Espo\ORM\Repository\RDBRepository;
use Espo\ORM\Repository\RDBSelectBuilder;
use PHPUnit\Framework\TestCase;

class ServiceTest extends TestCase
{
    private function service(Access $access, EntityManager $em, ?Projection $projection = null): Service
    {
        return new Service(
            $access, $em, $this->createMock(Config::class),
            (new \ReflectionClass(TenantRateLookup::class))->newInstanceWithoutConstructor(),
            new Ledger(), new Dataset(), $projection ?? $this->createMock(Projection::class),
        );
    }

    public function testSummaryAuthorizesBeforeReadingAnyBillingOrUsageData(): void
    {
        $access = $this->createMock(Access::class);
        $access->expects($this->once())->method('assertTenant')->with('foreign')->willThrowException(new Forbidden());
        $em = $this->createMock(EntityManager::class);
        $em->expects($this->never())->method('getRDBRepository');
        $this->expectException(Forbidden::class);
        $this->service($access, $em)->summary(['tenantId' => 'foreign']);
    }

    public function testDetailRejectsForeignRunEvenForAdministratorOfSelectedTenant(): void
    {
        $run = $this->createMock(Entity::class);
        $run->method('get')->with('tenantId')->willReturn('b');
        $em = $this->createMock(EntityManager::class);
        $em->method('getEntityById')->with('ChatwootAiAgentRun', 'run')->willReturn($run);
        $projection = $this->createMock(Projection::class);
        $projection->expects($this->never())->method('canRead');
        $this->expectException(NotFound::class);
        $this->service($this->createMock(Access::class), $em, $projection)->detail('run', ['tenantId' => 'a']);
    }

    public static function viewers(): array
    {
        return [[false, 'overview'], [true, 'overview'], [false, 'breakdown'], [true, 'breakdown'], [false, 'activity'], [true, 'activity']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('viewers')]
    public function testNormalApiPathLoadsDatedRatesScopesQueriesAndKeepsWholeTenantTotals(bool $admin, string $view): void
    {
        $runRows = [
            ['id' => '1', 'tenantId' => 'a', 'runAt' => '2026-08-01 10:00:00', 'kind' => 'customer-message', 'conversationId' => 'c'],
            ['id' => '2', 'tenantId' => 'a', 'runAt' => '2026-08-02 10:00:00', 'kind' => 'private-mention', 'conversationId' => 'c'],
            ['id' => '3', 'tenantId' => 'foreign', 'runAt' => '2026-08-02 10:00:00', 'kind' => 'customer-message', 'conversationId' => 'secret'],
            ['id' => '4', 'tenantId' => 'a', 'runAt' => '2026-08-02 11:00:00', 'kind' => 'private-mention', 'conversationId' => 'c', 'runOutcome' => 'failed'],
            ['id' => '5', 'tenantId' => 'a', 'runAt' => '2026-08-02 12:00:00', 'kind' => 'private-mention', 'conversationId' => 'c', 'billingWaived' => true],
        ];
        foreach ($runRows as &$row) {
            $row += ['inputTokens' => 100, 'cachedInputTokens' => 80, 'outputTokens' => 20, 'reasoningTokens' => 5];
        }
        unset($row);
        $data = [
            'ChatwootAiAgentRun' => $runRows,
            'TenantAiBillingRate' => [['id' => 'rate', 'tenantId' => 'a', 'effectiveFrom' => '2026-01-01', 'billingModel' => 'credit', 'planIncludedCredits' => 1, 'creditUnitPrice' => 0.5, 'currency' => 'BRL']],
            'Tenant' => [['id' => 'a']],
        ];
        $em = $this->createMock(EntityManager::class);
        $em->method('hasRepository')->willReturn(true);
        $em->method('getRDBRepository')->willReturnCallback(function ($scope) use ($data) {
            $where = [];
            $builder = $this->createMock(RDBSelectBuilder::class);
            foreach (['select', 'order', 'limit', 'sth'] as $method) $builder->method($method)->willReturnSelf();
            $builder->method('where')->willReturnCallback(function ($conditions) use (&$where, $builder, $scope) {
                $where = $conditions;
                if ($scope === 'ChatwootAiAgentRun') {
                    $this->assertSame('a', $where['tenantId']);
                    $this->assertArrayHasKey('runAt>=', $where);
                    $this->assertArrayHasKey('runAt<', $where);
                }
                return $builder;
            });
            $builder->method('find')->willReturnCallback(function () use (&$where, $data, $scope) {
                $rows = $data[$scope];
                if ($scope === 'ChatwootAiAgentRun') $rows = array_filter($rows, fn ($r) => $r['tenantId'] === $where['tenantId'] && $r['runAt'] >= $where['runAt>='] && $r['runAt'] < $where['runAt<']);
                return new EntityCollection(array_map(function ($data) {
                    $entity = $this->createMock(Entity::class);
                    $entity->method('getId')->willReturn($data['id']);
                    $entity->method('get')->willReturnCallback(fn ($field) => $data[$field] ?? null);
                    return $entity;
                }, array_values($rows)));
            });
            $repo = $this->createMock(RDBRepository::class);
            $repo->method('select')->willReturn($builder);
            $repo->method('where')->willReturnCallback(function ($conditions) use (&$where, $builder) { $where = $conditions; return $builder; });
            return $repo;
        });
        $access = $this->createMock(Access::class);
        $access->expects($this->once())->method('assertTenant')->with('a');
        $config = $this->createMock(Config::class);
        $config->method('get')->willReturn('UTC');
        $currency = $this->createMock(ConfigDataProvider::class);
        $currency->method('getDefaultCurrency')->willReturn('BRL');
        $user = $this->createMock(User::class);
        $user->method('isAdmin')->willReturn($admin);
        $acl = $this->createMock(Acl::class);
        $acl->method('checkField')->willReturn(true);
        $projection = $this->getMockBuilder(Projection::class)
            ->setConstructorArgs([$em, $acl, $this->createMock(SelectBuilderFactory::class), $user])
            ->onlyMethods(['accessibleIds'])->getMock();
        $projection->method('accessibleIds')->willReturn(['1' => true, '2' => true, '4' => true, '5' => true]);
        $service = new Service($access, $em, $config, new TenantRateLookup($em, $currency), new Ledger(), new Dataset(), $projection);
        $response = $service->summary(['tenantId' => 'a', 'month' => '2026-08', 'view' => $view, 'dimension' => 'kind', 'from' => '2026-08-02', 'limit' => 1]);
        $this->assertSame(4, $response['usage']['runs']);
        $this->assertSame(3, $response['filteredUsage']['runs']);
        $this->assertSame(1, $response['usage']['waivedRuns']);
        $this->assertSame(1, $response['filteredUsage']['failedRuns']);
        if ($view === 'breakdown') {
            $this->assertSame(1, $response['breakdown']['list'][0]['failedRuns']);
            $this->assertSame('private-mention', $response['breakdown']['list'][0]['key']);
            if ($admin) $this->assertSame(360, $response['breakdown']['list'][0]['analytics']['totalTokens']);
        }
        $this->assertSame(1, $response['billing']['covered']);
        $this->assertSame(1, $response['billing']['overage']);
        $this->assertSame(0.5, $response['billing']['charges'][0]['amount']);
        if ($admin) {
            $this->assertSame(480, $response['analytics']['totals']['totalTokens']);
            $this->assertSame(360, $response['analytics']['filtered']['totalTokens']);
            $this->assertSame(80.0, $response['analytics']['filtered']['tokenCacheHitPct']);
            if ($view === 'overview') $this->assertNull($response['analytics']['previous']['totalTokens']);
            if ($view === 'activity') {
                $this->assertCount(1, $response['activity']['list']);
                $this->assertSame(120, $response['activity']['list'][0]['analytics']['totalTokens']);
            }
        } else {
            $forbidden = array_merge(\Espo\Modules\FeatureAiUsage\Services\Analytics::FIELDS, ['analytics', 'totalTokens', 'tokenCacheHitPct', 'sources']);
            $check = function (array $data) use (&$check, $forbidden): void {
                foreach ($data as $key => $value) {
                    $this->assertNotContains($key, $forbidden);
                    if (is_array($value)) $check($value);
                }
            };
            $check($response);
        }
    }
}

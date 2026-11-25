<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCatchUp\Tests;

use Espo\Core\Acl;
use Espo\Core\Acl\AssignmentChecker\AssignmentCheckerManager;
use Espo\Core\Exceptions\Conflict;
use Espo\Core\Record\CreateResult;
use Espo\Core\Record\Service;
use Espo\Core\Record\ServiceContainer;
use Espo\Core\Utils\Config;
use Espo\Core\Utils\Log;
use Espo\Entities\User;
use Espo\Modules\FeatureCatchUp\Services\Acts;
use Espo\Modules\FeatureCatchUp\Services\DealStates;
use Espo\Modules\FeatureCatchUp\Services\DealTargets;
use Espo\Modules\FeatureCatchUp\Services\ProposedDeals;
use Espo\Modules\FeatureCatchUp\Services\ServiceFeed;
use Espo\Modules\FeatureCatchUp\Services\Verdicts;
use Espo\Modules\FeatureCatchUp\Services\Workspace;
use Espo\Modules\Global\Services\TeamTenantAccess;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The found deal Em dia creates by itself (`create` mode, the default) goes through the Opportunity record service, whose
 * create() answers a CreateResult since Espo 10. From 2026-11-23 to 2026-11-25 the auto path returned that wrapper where
 * an entity was declared, so every auto-created deal threw and rolled back and the card stayed a suggestion.
 */
class ProposedDealsTest extends TestCase
{
    private function deals(ServiceContainer $records, ?DealTargets $targets = null): ProposedDeals
    {
        return new ProposedDeals(
            $this->createMock(EntityManager::class), $this->createMock(Acl::class), $this->createMock(User::class),
            $this->createMock(Workspace::class), $this->createMock(Verdicts::class), $this->createMock(ServiceFeed::class),
            $targets ?? $this->createMock(DealTargets::class), $records, $this->createMock(AssignmentCheckerManager::class),
            $this->createMock(Config::class), $this->createMock(TeamTenantAccess::class), $this->createMock(Acts::class),
            $this->createMock(DealStates::class), $this->createMock(Log::class),
        );
    }

    private function createWithFallback(ProposedDeals $deals, array $data): ?Entity
    {
        return (new ReflectionMethod(ProposedDeals::class, 'createWithFallback'))->invoke($deals, $data, 'f1', 's2');
    }

    public function testAutoCreateGetsTheDealEntityOutOfTheRecordServicesCreateResult(): void
    {
        $deal = $this->createMock(Entity::class);
        $service = $this->createMock(Service::class);
        $service->expects(self::once())->method('create')
            ->with(self::callback(fn (object $data) => $data->opportunityStageId === 's2' && $data->createdByAIAgent === true))
            ->willReturn(new CreateResult($deal));
        $records = $this->createMock(ServiceContainer::class);
        $records->method('get')->with('Opportunity')->willReturn($service);

        $created = $this->createWithFallback($this->deals($records), ['name' => 'Kelly Rota - Evento', 'funnelId' => 'f1',
            'opportunityStageId' => 's2', 'createdByAIAgent' => true]);

        self::assertSame($deal, $created);
    }

    public function testAStageWithRequiredFieldsFallsBackToTheFunnelsFirstOpenStage(): void
    {
        $deal = $this->createMock(Entity::class);
        $stages = [];
        $service = $this->createMock(Service::class);
        $service->expects(self::exactly(2))->method('create')->willReturnCallback(function (object $data) use (&$stages, $deal) {
            $stages[] = $data->opportunityStageId;
            if ($data->opportunityStageId === 's2') throw new Conflict('opportunityStageRequirements');
            return new CreateResult($deal);
        });
        $records = $this->createMock(ServiceContainer::class);
        $records->method('get')->willReturn($service);
        $targets = $this->createMock(DealTargets::class);
        $targets->method('firstOpenStageId')->with('f1')->willReturn('s1');

        $created = $this->createWithFallback($this->deals($records, $targets), ['name' => 'Deal', 'funnelId' => 'f1', 'opportunityStageId' => 's2']);

        self::assertSame($deal, $created);
        self::assertSame(['s2', 's1'], $stages);
    }
}

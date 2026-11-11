<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCatchUp\Tests;

use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Select\SelectBuilderFactory;
use Espo\Entities\User;
use Espo\Modules\FeatureCatchUp\Services\DealTargets;
use Espo\Modules\FeatureCatchUp\Services\Parties;
use Espo\Modules\FeatureCatchUp\Services\Records;
use Espo\Modules\FeatureCatchUp\Services\Summary;
use Espo\Modules\FeatureCatchUp\Services\Verdicts;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use PHPUnit\Framework\TestCase;

/** Where a deal Em dia found can go (prompt version 13), and the stored cards from before it. */
class DealTargetsTest extends TestCase
{
    private function entity(string $id, array $values): Entity
    {
        $entity = $this->createMock(Entity::class);
        $entity->method('getId')->willReturn($id);
        $entity->method('get')->willReturnCallback(fn (string $name) => $values[$name] ?? null);
        return $entity;
    }

    /**
     * Consultas (default): Novo 10%, Agendado 60%, Ganho 100% (the lost 0% stage is filtered by the query). Emagrecimento:
     * only a won stage. Arquivo: no stage a deal may start in.
     */
    private function targets(): DealTargets
    {
        $funnels = [
            $this->entity('f1', ['name' => 'Consultas', 'isDefault' => true, 'description' => "Consultas\n  médicas"]),
            $this->entity('f2', ['name' => 'Emagrecimento', 'isDefault' => false]),
            $this->entity('f3', ['name' => 'Arquivo', 'isDefault' => false]),
        ];
        $stages = [
            'f1' => [
                $this->entity('s1', ['name' => 'Novo', 'probability' => 10]),
                $this->entity('s2', ['name' => 'Agendado', 'probability' => 60, 'description' => 'Data marcada']),
                $this->entity('s3', ['name' => 'Ganho', 'probability' => 100, 'description' => str_repeat('x', 400)]),
            ],
            'f2' => [$this->entity('s4', ['name' => 'Fechado', 'probability' => 100])],
            'f3' => [],
        ];
        $targets = $this->getMockBuilder(DealTargets::class)
            ->setConstructorArgs([$this->createMock(EntityManager::class), $this->createMock(SelectBuilderFactory::class)])
            ->onlyMethods(['funnels', 'stages'])->getMock();
        $targets->method('funnels')->willReturn($funnels);
        $targets->method('stages')->willReturnCallback(fn (string $id) => $stages[$id]);
        return $targets;
    }

    public function testTheAnalysisGetsTheFunnelsWithTheirOpenAndWonStagesDefaultFirst(): void
    {
        $sent = $this->targets()->forPrompt('tenant');
        self::assertSame(['f1', 'f2'], array_column($sent, 'id'), 'a funnel with no stage to start in is left out');
        self::assertSame('Consultas médicas', $sent[0]['description']);
        self::assertSame([['s1', 'open'], ['s2', 'open'], ['s3', 'won']], array_map(fn ($s) => [$s['id'], $s['kind']], $sent[0]['stages']));
        self::assertSame('Data marcada', $sent[0]['stages'][1]['description']);
        self::assertSame(300, mb_strlen($sent[0]['stages'][2]['description']));
        self::assertNull($sent[0]['stages'][0]['description']);
    }

    public function testAProposalKeepsItsFunnelAndStageOnlyWhenTheyAreOnesItWasOffered(): void
    {
        $targets = $this->targets();
        $deal = ['name' => 'Ana - Consulta', 'amount' => 800.0];
        self::assertSame([...$deal, 'funnelId' => 'f1', 'stageId' => 's3'], $targets->sanitize('t', [...$deal, 'funnelId' => 'f1', 'stageId' => 's3']));
        self::assertSame([...$deal, 'funnelId' => null, 'stageId' => null], $targets->sanitize('t', [...$deal, 'funnelId' => 'f1', 'stageId' => 's4']),
            'a stage of another funnel');
        self::assertSame([...$deal, 'funnelId' => null, 'stageId' => null], $targets->sanitize('t', [...$deal, 'funnelId' => 'nope', 'stageId' => 's1']));
        self::assertSame($deal, $targets->sanitize('t', $deal), 'an answer from a backend before version 13 stays outdated');
        self::assertTrue(Verdicts::outdated(['verdict' => 'new_deal', 'card' => ['proposedDeal' => $targets->sanitize('t', $deal)]]));
    }

    public function testADealGoesInTheChosenPairOrTheDefaultFunnelsFirstOpenStage(): void
    {
        $targets = $this->targets();
        self::assertSame(['f2', 's4'], $targets->resolve('t', 'f2', 's4'));
        self::assertSame(['f1', 's1'], $targets->resolve('t', null, null));
        foreach ([['f1', null], [null, 's1'], ['f1', 's4'], ['f9', 's1']] as [$funnel, $stage]) {
            try {
                $targets->resolve('t', $funnel, $stage);
                self::fail('Accepted ' . json_encode([$funnel, $stage]));
            } catch (BadRequest $e) {
                self::assertSame('invalidStage', $e->getMessage());
            }
        }
    }

    public function testWithoutADefaultFunnelOnlyTheOnlyOneIsTaken(): void
    {
        $build = function (array $funnels): DealTargets {
            $targets = $this->getMockBuilder(DealTargets::class)
                ->setConstructorArgs([$this->createMock(EntityManager::class), $this->createMock(SelectBuilderFactory::class)])
                ->onlyMethods(['funnels', 'stages'])->getMock();
            $targets->method('funnels')->willReturn($funnels);
            $targets->method('stages')->willReturn([$this->entity('s', ['probability' => 20])]);
            return $targets;
        };
        self::assertSame(['only', 's'], $build([$this->entity('only', ['isDefault' => false])])->resolve('t', null, null));
        foreach ([[[$this->entity('a', []), $this->entity('b', [])], 'funnelRequired'], [[], 'noFunnel']] as [$funnels, $error]) {
            try {
                $build($funnels)->resolve('t', null, null);
                self::fail("Expected $error");
            } catch (BadRequest $e) {
                self::assertSame($error, $e->getMessage());
            }
        }
    }

    public function testOnlyAFoundDealFromBeforeFunnelsOrACardMarkedForANewDraftIsAnalysedAgain(): void
    {
        self::assertTrue(Verdicts::outdated(['verdict' => 'new_deal', 'card' => ['proposedDeal' => ['name' => 'Ana', 'amount' => null]]]));
        self::assertFalse(Verdicts::outdated(['verdict' => 'new_deal', 'card' => ['proposedDeal' => ['name' => 'Ana', 'funnelId' => null, 'stageId' => null]]]));
        self::assertTrue(Verdicts::outdated(['verdict' => 'customer_waiting', 'card' => ['redraft' => true]]));
        self::assertFalse(Verdicts::outdated(['verdict' => 'customer_waiting', 'card' => ['headline' => 'H']]));
        self::assertFalse(Verdicts::outdated(['verdict' => 'none', 'card' => null]));
    }

    public function testAnOldFoundDealIsReadAgainInFullAndTheNewProposalIsCheckedBeforeItIsStored(): void
    {
        $verdicts = $this->createMock(Verdicts::class);
        $verdicts->method('find')->willReturn(['verdict' => 'new_deal', 'reason' => 'r', 'card' => ['headline' => 'H', 'proposedDeal' => ['name' => 'Ana']]]);
        $stored = null;
        $verdicts->expects(self::once())->method('store')->willReturnCallback(function (...$args) use (&$stored): void { $stored = $args; });
        $targets = $this->createMock(DealTargets::class);
        $targets->expects(self::once())->method('sanitize')
            ->with('tenant', ['name' => 'Ana - Consulta', 'amount' => 800, 'funnelId' => 'f1', 'stageId' => 'bogus'])
            ->willReturn(['name' => 'Ana - Consulta', 'amount' => 800, 'funnelId' => null, 'stageId' => null]);
        $summary = $this->getMockBuilder(Summary::class)
            ->setConstructorArgs([$this->createMock(User::class), $verdicts, $this->createMock(Parties::class), $this->createMock(Records::class), $targets])
            ->onlyMethods(['request'])->getMock();
        $summary->expects(self::once())->method('request')
            ->with(self::callback(fn (array $snapshot) => $snapshot['record'] === null))
            ->willReturn(['status' => 'completed', 'card' => ['verdict' => 'new_deal', 'reason' => 'Pagou o sinal', 'headline' => 'Ana pagou',
                'whyNow' => 'Pix', 'proposedDeal' => ['name' => 'Ana - Consulta', 'amount' => 800, 'funnelId' => 'f1', 'stageId' => 'bogus']]]);
        $result = $summary->analyse(['id' => 'c1', 'type' => 'conversation', 'tenantId' => 'tenant', 'revision' => 'rev', 'evidenceScope' => 'scope',
            'evidence' => [], 'facts' => [], 'catchUpPolicy' => ['instance' => 'https://chat.test'], 'participants' => [],
            'record' => ['agreed' => [], 'promises' => []]], 'pt-BR');
        self::assertSame(['new_deal', false], [$result['verdict'], $result['cached']]);
        self::assertSame(['funnelId' => null, 'stageId' => null], array_intersect_key($stored[8]['proposedDeal'], ['funnelId' => 1, 'stageId' => 1]));
    }

    public function testAFoundDealWithItsFunnelIsServedFromTheStore(): void
    {
        $card = ['headline' => 'H', 'proposedDeal' => ['name' => 'Ana', 'funnelId' => 'f1', 'stageId' => 's1']];
        $verdicts = $this->createMock(Verdicts::class);
        $verdicts->method('find')->willReturn(['verdict' => 'new_deal', 'reason' => 'r', 'card' => $card]);
        $summary = $this->getMockBuilder(Summary::class)
            ->setConstructorArgs([$this->createMock(User::class), $verdicts, $this->createMock(Parties::class), $this->createMock(Records::class),
                $this->createMock(DealTargets::class)])
            ->onlyMethods(['request'])->getMock();
        $summary->expects(self::never())->method('request');
        $result = $summary->analyse(['id' => 'c1', 'type' => 'conversation', 'tenantId' => 'tenant', 'revision' => 'rev', 'evidenceScope' => 'scope'], 'pt-BR');
        self::assertSame([true, $card], [$result['cached'], $result['card']]);
    }
}

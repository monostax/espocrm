<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCatchUp\Tests;

use Espo\Entities\User;
use Espo\Modules\FeatureCatchUp\Services\DealTargets;
use Espo\Modules\FeatureCatchUp\Services\Parties;
use Espo\Modules\FeatureCatchUp\Services\ProposedDeals;
use Espo\Modules\FeatureCatchUp\Services\Records;
use Espo\Modules\FeatureCatchUp\Services\Summary;
use Espo\Modules\FeatureCatchUp\Services\Verdicts;
use PHPUnit\Framework\TestCase;

class SummaryTest extends TestCase
{
    /** A link from Avisos puts a card further down the deck first; one someone acted on must not come back as a card. */
    public function testAStoredCardSomeoneActedOnIsNothingToShowNotTheCardAgain(): void
    {
        $verdicts = $this->createMock(Verdicts::class);
        $verdicts->method('find')->willReturn([
            'verdict' => 'customer_waiting', 'reason' => 'Asked for the price.', 'card' => ['headline' => 'Enviar o preço'], 'resolved' => true,
        ]);
        $verdicts->expects(self::never())->method('store');
        $summary = $this->getMockBuilder(Summary::class)->setConstructorArgs([
            $this->createMock(User::class), $verdicts, $this->createMock(Parties::class),
            $this->createMock(Records::class), $this->createMock(DealTargets::class), $this->createMock(ProposedDeals::class),
        ])->onlyMethods(['request'])->getMock();
        $summary->expects(self::never())->method('request');

        $answer = $summary->analyse(['type' => 'conversation', 'id' => 'c1', 'revision' => 'r1', 'evidenceScope' => 's', 'tenantId' => 't'], 'pt-BR');

        self::assertSame('completed', $answer['status']);
        self::assertSame('none', $answer['verdict']);
        self::assertNull($answer['card']);
    }

    public function testCreateModeAutoCreatesOpenDealMirrorsVerdictAndResolvesConversation(): void
    {
        $verdicts = $this->createMock(Verdicts::class);
        $verdicts->method('find')->willReturn(null);
        $stored = [];
        $verdicts->expects(self::exactly(2))->method('store')
            ->willReturnCallback(function (...$args) use (&$stored): void { $stored[] = $args; });
        $verdicts->expects(self::once())->method('resolve')->with('ChatwootConversation', 'c1', 'r1');

        $records = $this->createMock(Records::class);
        $recordCalls = [];
        $records->expects(self::exactly(2))->method('store')
            ->willReturnCallback(function (...$args) use (&$recordCalls): void { $recordCalls[] = $args; });

        $targets = $this->createMock(DealTargets::class);
        $targets->method('sanitize')->willReturnArgument(1);

        $created = [
            'id' => 'opp1', 'name' => 'Rosélia - Locação', 'amount' => null, 'amountCurrency' => 'BRL',
            'status' => 'Open', 'funnelId' => 'f1', 'funnelName' => 'Vendas',
            'stageId' => 's1', 'stageName' => 'Em Qualificação', 'stageWon' => false, 'createdByAi' => true,
        ];
        $proposedDeals = $this->createMock(ProposedDeals::class);
        $proposedDeals->expects(self::once())->method('autoCreate')
            ->with('c1', 't1', self::callback(fn (array $card) => ($card['proposedDeal']['name'] ?? null) === 'Rosélia - Locação'), [])
            ->willReturn(['deal' => $created, 'revision' => 'deal-rev-1', 'closedKey' => null]);

        $summary = $this->getMockBuilder(Summary::class)->setConstructorArgs([
            $this->createMock(User::class), $verdicts, $this->createMock(Parties::class), $records, $targets, $proposedDeals,
        ])->onlyMethods(['request'])->getMock();
        $summary->method('request')->willReturn(['status' => 'completed', 'requestId' => str_repeat('a', 64), 'card' => [
            'verdict' => 'loops', 'reason' => 'Pediu orçamento', 'headline' => 'Responder Rosélia', 'whyNow' => 'Quer orçamento',
            'draft' => 'Oi Rosélia!',
            'proposedDeal' => ['name' => 'Rosélia - Locação', 'amount' => null, 'funnelId' => 'f1', 'stageId' => 's1'],
            'record' => ['agreed' => [], 'promises' => [['id' => 'p1', 'side' => 'ours', 'text' => 'enviar orçamento', 'dueAt' => null, 'status' => 'open']]],
        ]]);

        $answer = $summary->analyse([
            'type' => 'conversation', 'id' => 'c1', 'revision' => 'r1', 'evidenceScope' => 'scope1', 'sourceScope' => 'src1',
            'tenantId' => 't1', 'evidence' => [], 'facts' => [], 'participants' => [], 'closedDeals' => [],
            'catchUpPolicy' => ['enabled' => true, 'instance' => 'https://chat.test', 'accountId' => '1', 'dailyLimit' => 1000, 'dealMode' => 'create'],
        ], 'pt-BR');

        self::assertSame('customer_waiting', $answer['verdict']);
        self::assertSame($created, $answer['card']['createdDeal']);
        self::assertArrayNotHasKey('proposedDeal', $answer['card']);
        self::assertSame(['Opportunity', 'opp1', 'deal-rev-1'], [$stored[0][1], $stored[0][2], $stored[0][3]]);
        self::assertSame($created, $stored[0][8]['createdDeal']);
        self::assertSame(['ChatwootConversation', 'c1', 'r1'], [$stored[1][1], $stored[1][2], $stored[1][3]]);
        self::assertSame('Opportunity', $recordCalls[1][1]);
        self::assertSame('opp1', $recordCalls[1][2]);
    }

    public function testCreateModeAutoCreatesWonDealAndKeepsCardInServiceFeed(): void
    {
        $verdicts = $this->createMock(Verdicts::class);
        $verdicts->method('find')->willReturn(null);
        $stored = null;
        $verdicts->expects(self::once())->method('store')
            ->willReturnCallback(function (...$args) use (&$stored): void { $stored = $args; });
        $verdicts->expects(self::never())->method('resolve');

        $targets = $this->createMock(DealTargets::class);
        $targets->method('sanitize')->willReturnArgument(1);

        $created = [
            'id' => 'opp-won', 'name' => 'Ana - Consulta', 'amount' => 350.0, 'amountCurrency' => 'BRL',
            'status' => 'Won', 'funnelId' => 'f1', 'funnelName' => 'Vendas',
            'stageId' => 's-won', 'stageName' => 'Ganho', 'stageWon' => true, 'createdByAi' => true,
        ];
        $proposedDeals = $this->createMock(ProposedDeals::class);
        $proposedDeals->expects(self::once())->method('autoCreate')
            ->willReturn(['deal' => $created, 'revision' => null, 'closedKey' => 'key-won-1']);

        $summary = $this->getMockBuilder(Summary::class)->setConstructorArgs([
            $this->createMock(User::class), $verdicts, $this->createMock(Parties::class), $this->createMock(Records::class), $targets, $proposedDeals,
        ])->onlyMethods(['request'])->getMock();
        $summary->method('request')->willReturn(['status' => 'completed', 'card' => [
            'verdict' => 'new_deal', 'reason' => 'Pagou', 'headline' => 'Consulta confirmada', 'whyNow' => 'Pagou Pix',
            'proposedDeal' => ['name' => 'Ana - Consulta', 'amount' => 350, 'funnelId' => 'f1', 'stageId' => 's-won'],
        ]]);

        $answer = $summary->analyse([
            'type' => 'conversation', 'id' => 'c1', 'revision' => 'r1', 'evidenceScope' => 'scope1',
            'tenantId' => 't1', 'evidence' => [], 'facts' => [], 'participants' => [], 'closedDeals' => [],
            'catchUpPolicy' => ['enabled' => true, 'instance' => 'https://chat.test', 'accountId' => '1', 'dailyLimit' => 1000],
        ], 'pt-BR');

        self::assertSame('new_deal', $answer['verdict']);
        self::assertSame($created, $answer['card']['createdDeal']);
        self::assertSame(['key-won-1'], $answer['card']['closedDeals']);
        self::assertSame(['ChatwootConversation', 'c1', 'r1'], [$stored[1], $stored[2], $stored[3]]);
        self::assertSame(['key-won-1'], $stored[11]);
    }

    public function testSuggestionModeDoesNotAutoCreateAndKeepsProposedDeal(): void
    {
        $verdicts = $this->createMock(Verdicts::class);
        $verdicts->method('find')->willReturn(null);
        $verdicts->expects(self::once())->method('store');
        $targets = $this->createMock(DealTargets::class);
        $targets->method('sanitize')->willReturnArgument(1);
        $proposedDeals = $this->createMock(ProposedDeals::class);
        $proposedDeals->expects(self::never())->method('autoCreate');

        $summary = $this->getMockBuilder(Summary::class)->setConstructorArgs([
            $this->createMock(User::class), $verdicts, $this->createMock(Parties::class), $this->createMock(Records::class), $targets, $proposedDeals,
        ])->onlyMethods(['request'])->getMock();
        $summary->method('request')->willReturn(['status' => 'completed', 'card' => [
            'verdict' => 'new_deal', 'reason' => 'Quer orçamento', 'headline' => 'Criar negócio', 'whyNow' => 'Pediu preço',
            'proposedDeal' => ['name' => 'Rosélia', 'amount' => null, 'funnelId' => 'f1', 'stageId' => 's1'],
        ]]);

        $answer = $summary->analyse([
            'type' => 'conversation', 'id' => 'c1', 'revision' => 'r1', 'evidenceScope' => 'scope1',
            'tenantId' => 't1', 'evidence' => [], 'facts' => [], 'participants' => [], 'closedDeals' => [],
            'catchUpPolicy' => ['enabled' => true, 'instance' => 'https://chat.test', 'accountId' => '1', 'dailyLimit' => 1000, 'dealMode' => 'suggestion'],
        ], 'pt-BR');

        self::assertSame('new_deal', $answer['verdict']);
        self::assertSame('Rosélia', $answer['card']['proposedDeal']['name']);
        self::assertArrayNotHasKey('createdDeal', $answer['card']);
    }

    public function testCachedProposalIsPromotedWithoutCallingBackendWhenAccountUsesCreateMode(): void
    {
        $verdicts = $this->createMock(Verdicts::class);
        $verdicts->method('find')->willReturn([
            'verdict' => 'new_deal', 'reason' => 'Quer orçamento', 'resolved' => false,
            'card' => ['headline' => 'Criar negócio', 'whyNow' => 'Pediu preço',
                'proposedDeal' => ['name' => 'Rosélia', 'amount' => null, 'funnelId' => 'f1', 'stageId' => 's1'], 'closedDeals' => []],
        ]);
        $verdicts->expects(self::exactly(2))->method('store');
        $verdicts->expects(self::once())->method('resolve')->with('ChatwootConversation', 'c1', 'r1');

        $created = [
            'id' => 'opp2', 'name' => 'Rosélia', 'amount' => null, 'amountCurrency' => 'BRL',
            'status' => 'Open', 'funnelId' => 'f1', 'funnelName' => 'Vendas',
            'stageId' => 's1', 'stageName' => 'Em Qualificação', 'stageWon' => false, 'createdByAi' => true,
        ];
        $proposedDeals = $this->createMock(ProposedDeals::class);
        $proposedDeals->expects(self::once())->method('autoCreate')
            ->willReturn(['deal' => $created, 'revision' => 'deal-rev-2', 'closedKey' => null]);

        $summary = $this->getMockBuilder(Summary::class)->setConstructorArgs([
            $this->createMock(User::class), $verdicts, $this->createMock(Parties::class),
            $this->createMock(Records::class), $this->createMock(DealTargets::class), $proposedDeals,
        ])->onlyMethods(['request'])->getMock();
        $summary->expects(self::never())->method('request');

        $answer = $summary->analyse([
            'type' => 'conversation', 'id' => 'c1', 'revision' => 'r1', 'evidenceScope' => 'scope1',
            'tenantId' => 't1', 'evidence' => [], 'facts' => [], 'closedDeals' => [],
            'catchUpPolicy' => ['enabled' => true, 'instance' => 'https://chat.test', 'accountId' => '1', 'dailyLimit' => 1000, 'dealMode' => 'create'],
        ], 'pt-BR');

        self::assertTrue($answer['cached']);
        self::assertSame($created, $answer['card']['createdDeal']);
        self::assertArrayNotHasKey('proposedDeal', $answer['card']);
    }

    public function testCreatedDealCardIsNeverOutdatedByClosedDeals(): void
    {
        $card = ['verdict' => 'new_deal', 'card' => ['headline' => 'H', 'createdDeal' => ['id' => 'opp1', 'name' => 'Ana']]];
        self::assertFalse(Verdicts::outdated($card, ['k1']));
        self::assertTrue(Verdicts::outdated(['verdict' => 'customer_waiting', 'card' => ['headline' => 'H', 'redraft' => true, 'createdDeal' => ['id' => 'opp1']]], ['k1']));
    }
}

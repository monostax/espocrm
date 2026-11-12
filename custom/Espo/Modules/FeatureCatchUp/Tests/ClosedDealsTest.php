<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCatchUp\Tests;

use DateTimeImmutable;
use DateTimeZone;
use Espo\Core\Acl;
use Espo\Core\Select\SelectBuilderFactory;
use Espo\Core\Utils\DataCache;
use Espo\Entities\User;
use Espo\Modules\FeatureCatchUp\Services\ChatwootAccess;
use Espo\Modules\FeatureCatchUp\Services\DealTargets;
use Espo\Modules\FeatureCatchUp\Services\Ownership;
use Espo\Modules\FeatureCatchUp\Services\Parties;
use Espo\Modules\FeatureCatchUp\Services\Records;
use Espo\Modules\FeatureCatchUp\Services\ServiceFeed;
use Espo\Modules\FeatureCatchUp\Services\Summary;
use Espo\Modules\FeatureCatchUp\Services\Transcript;
use Espo\Modules\FeatureCatchUp\Services\Verdicts;
use Espo\Modules\FeatureCatchUp\Services\Workspace;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use PHPUnit\Framework\TestCase;

/**
 * A conversation whose deal is already won or lost (2026-11-11): the AI agent won Ariane's consultation a minute after she
 * confirmed, and the conversation's analysis, told it had no deal, proposed the same deal again. The analysis now reads
 * the conversation's closed deals, and a found deal analysed without one of them is analysed again.
 */
class ClosedDealsTest extends TestCase
{
    private function entity(string $id, array $values): Entity
    {
        $entity = $this->createMock(Entity::class);
        $entity->method('get')->willReturnCallback(fn ($field) => $values[$field] ?? null);
        $entity->method('getId')->willReturn($id);
        return $entity;
    }

    /** @param array<string, array<string, mixed>> $deals */
    private function feed(array $deals, array $hidden = []): ServiceFeed
    {
        $acl = $this->createMock(Acl::class);
        $acl->method('checkEntityRead')->willReturnCallback(fn (Entity $deal) => !in_array($deal->getId(), $hidden, true));
        $statement = $this->createMock(\PDOStatement::class);
        $statement->expects(self::once())->method('execute')->with(['conv', 'tenant'])->willReturn(true);
        $statement->method('fetchAll')->willReturn(array_keys($deals));
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('prepare')->willReturnCallback(function (string $sql) use ($statement) {
            self::assertStringContainsString("o.status IN ('Won','Lost')", $sql, 'an open deal makes the conversation a Negócios card');
            return $statement;
        });
        $em = $this->createMock(EntityManager::class);
        $em->method('getPDO')->willReturn($pdo);
        $em->method('getEntityById')->willReturnCallback(fn ($type, $id) => isset($deals[$id]) ? $this->entity($id, $deals[$id]) : null);
        return new ServiceFeed($em, $this->createMock(SelectBuilderFactory::class), $acl, $this->createMock(User::class),
            $this->createMock(Workspace::class), $this->createMock(Ownership::class), $this->createMock(Verdicts::class),
            $this->createMock(Transcript::class), $this->createMock(Parties::class), $this->createMock(DataCache::class),
            $this->createMock(Records::class), $this->createMock(ChatwootAccess::class));
    }

    public function testTheConversationsClosedDealsAreTheNewestThreeTheReaderMayRead(): void
    {
        $deals = [
            'won' => ['name' => 'Consulta Dra. Michele - Ariane Cristina', 'status' => 'Won', 'amount' => 800, 'amountCurrency' => 'BRL',
                'createdAt' => '2026-11-04 12:00:03', 'closeDate' => '2026-11-11'],
            'hidden' => ['name' => 'Segredo', 'status' => 'Won', 'createdAt' => '2026-10-20 12:00:00'],
            'lost' => ['name' => 'Bioimpedância', 'status' => 'Lost', 'createdAt' => '2026-10-01 12:00:00', 'closeDate' => '2026-10-03'],
            'older' => ['name' => 'Retorno', 'status' => 'Won', 'createdAt' => '2026-09-01 12:00:00'],
            'oldest' => ['name' => 'Primeira consulta', 'status' => 'Won', 'createdAt' => '2026-08-01 12:00:00'],
        ];
        $closed = $this->feed($deals, ['hidden'])->closedDeals('conv', 'tenant');

        self::assertSame(['won', 'lost', 'older'], array_map(fn (Entity $deal) => $deal->getId(), $closed));
        $keys = ServiceFeed::dealKeys($closed);
        self::assertCount(3, array_unique($keys));
        self::assertSame(16, strlen($keys[0]));
        self::assertStringNotContainsString('won', implode('', $keys), 'a shared card never names a deal');
        self::assertNotSame($keys[0], ServiceFeed::dealKeys([$this->entity('won', ['status' => 'Lost'])])[0], 'a status change is a new key');
    }

    public function testTheAnalysisReadsTheClosedDealsOnlyWhenThereAreAny(): void
    {
        $facts = new \ReflectionMethod(ServiceFeed::class, 'facts');
        $feed = $this->getMockBuilder(ServiceFeed::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
        $candidate = ['name' => 'Ariane Cristina', 'contactName' => 'Ariane Cristina', 'group' => false, 'inboxName' => 'Erivânia',
            'ownerName' => null, 'quietDays' => 0, 'customerSpokeLast' => true];
        $now = new DateTimeImmutable('2026-11-11 15:00:00', new DateTimeZone('America/Sao_Paulo'));
        $won = $this->entity('won', ['name' => 'Consulta Dra. Michele - Ariane Cristina', 'status' => 'Won', 'amount' => 800,
            'amountCurrency' => 'BRL', 'createdAt' => '2026-11-04 12:00:03', 'closeDate' => '2026-11-11']);

        self::assertArrayNotHasKey('closedDeals', $facts->invoke($feed, $candidate, $now), 'no deal: the backend request stays the same');
        self::assertSame('Consulta Dra. Michele - Ariane Cristina (Won BRL 800, opened 2026-11-04, closed 2026-11-11)',
            $facts->invoke($feed, $candidate, $now, [$won])['closedDeals']);
    }

    public function testAFoundDealIsAnalysedAgainOnlyForAClosedDealItWasNotAnalysedWith(): void
    {
        $found = fn (?array $known) => ['verdict' => 'new_deal', 'card' => ['proposedDeal' => ['name' => 'Ana', 'funnelId' => 'f', 'stageId' => 's']]
            + ($known === null ? [] : ['closedDeals' => $known])];

        self::assertFalse(Verdicts::outdated($found(null)), 'a conversation without closed deals keeps its card');
        self::assertTrue(Verdicts::outdated($found(null), ['k1']), 'a card from before closed deals were read');
        self::assertTrue(Verdicts::outdated($found([]), ['k1']), 'a deal closed after the card was made');
        self::assertFalse(Verdicts::outdated($found(['k1']), ['k1']));
        self::assertFalse(Verdicts::outdated($found(['k1', 'k2']), ['k1']), 'a reader who may read fewer deals gets the stored card');
        self::assertTrue(Verdicts::outdated($found(['k1']), ['k1', 'k3']));
        self::assertFalse(Verdicts::outdated(['verdict' => 'customer_waiting', 'card' => ['headline' => 'H']], ['k1']), 'only cards that offer a deal');
        $reply = ['verdict' => 'customer_waiting', 'card' => ['headline' => 'H', 'proposedDeal' => ['name' => 'Ana', 'funnelId' => 'f', 'stageId' => 's'], 'closedDeals' => []]];
        self::assertTrue(Verdicts::outdated($reply, ['k1']), 'a reply card that offers a deal, made before a deal closed');
        self::assertFalse(Verdicts::outdated($reply));
        self::assertFalse(Verdicts::outdated(['verdict' => 'none', 'card' => null], ['k1']));
    }

    /** @return array{Summary, Verdicts} */
    private function summary(?array $stored): array
    {
        $verdicts = $this->createMock(Verdicts::class);
        $verdicts->method('find')->willReturn($stored);
        $targets = $this->createMock(DealTargets::class);
        $targets->method('sanitize')->willReturnArgument(1);
        $summary = $this->getMockBuilder(Summary::class)
            ->setConstructorArgs([$this->createMock(User::class), $verdicts, $this->createMock(Parties::class), $this->createMock(Records::class), $targets])
            ->onlyMethods(['request'])->getMock();
        return [$summary, $verdicts];
    }

    private function snapshot(array $closedDeals): array
    {
        return ['id' => 'c1', 'type' => 'conversation', 'tenantId' => 'tenant', 'revision' => 'rev', 'evidenceScope' => 'scope',
            'evidence' => [], 'facts' => [], 'catchUpPolicy' => ['instance' => 'https://chat.test'], 'participants' => [],
            'record' => ['agreed' => [], 'promises' => []], 'closedDeals' => $closedDeals];
    }

    public function testAFoundDealRemembersTheClosedDealsItWasAnalysedWith(): void
    {
        [$summary, $verdicts] = $this->summary(['verdict' => 'new_deal', 'reason' => 'r',
            'card' => ['headline' => 'Registrar consulta', 'proposedDeal' => ['name' => 'Ariane', 'funnelId' => 'f', 'stageId' => 'won']]]);
        $summary->expects(self::once())->method('request')
            ->with(self::callback(fn (array $snapshot) => $snapshot['record'] === null))
            ->willReturn(['status' => 'completed', 'card' => ['verdict' => 'new_deal', 'reason' => 'Quer um retorno', 'headline' => 'Agendar retorno',
                'whyNow' => 'w', 'proposedDeal' => ['name' => 'Ariane - Retorno', 'amount' => null, 'funnelId' => 'f', 'stageId' => 's1']]]);
        $stored = null;
        $verdicts->expects(self::once())->method('store')->willReturnCallback(function (...$args) use (&$stored): void { $stored = $args; });

        $result = $summary->analyse($this->snapshot(['k1']), 'pt-BR');

        self::assertSame(['new_deal', false], [$result['verdict'], $result['cached']], 'the stale card is analysed again, in full');
        self::assertSame(['k1'], $stored[8]['closedDeals']);
        self::assertSame(['k1'], $stored[11], 'the store replaces the stale card');
    }

    public function testAReplyCardThatOffersADealRemembersTheClosedDealsToo(): void
    {
        [$summary, $verdicts] = $this->summary(null);
        $summary->method('request')->willReturn(['status' => 'completed', 'card' => ['verdict' => 'loops', 'reason' => 'Pediu o link da reunião',
            'headline' => 'Enviar o link', 'whyNow' => 'w', 'draft' => 'Oi Alysson, segue o link',
            'record' => ['agreed' => [], 'promises' => [['id' => 'p1', 'side' => 'ours', 'text' => 'Enviar o link da reunião', 'status' => 'open']]],
            'proposedDeal' => ['name' => 'Alysson - Demonstração', 'amount' => null, 'funnelId' => 'f', 'stageId' => 's1']]]);
        $stored = null;
        $verdicts->expects(self::once())->method('store')->willReturnCallback(function (...$args) use (&$stored): void { $stored = $args; });

        self::assertSame('customer_waiting', $summary->analyse($this->snapshot(['k1']), 'pt-BR')['verdict']);
        self::assertSame('Alysson - Demonstração', $stored[8]['proposedDeal']['name']);
        self::assertSame(['k1'], $stored[8]['closedDeals']);
    }

    public function testAnAnswerThatIsNoLongerAFoundDealRemembersNothing(): void
    {
        [$summary, $verdicts] = $this->summary(null);
        $summary->method('request')->willReturn(['status' => 'completed', 'card' => ['verdict' => 'none', 'reason' => 'Consulta já ganha']]);
        $stored = null;
        $verdicts->expects(self::once())->method('store')->willReturnCallback(function (...$args) use (&$stored): void { $stored = $args; });

        self::assertSame('none', $summary->analyse($this->snapshot(['k1']), 'pt-BR')['verdict']);
        self::assertNull($stored[8]);
    }

    public function testAReaderWhoMayReadFewerClosedDealsGetsTheStoredFoundDeal(): void
    {
        [$summary] = $this->summary(['verdict' => 'new_deal', 'reason' => 'r',
            'card' => ['proposedDeal' => ['name' => 'Ana', 'funnelId' => 'f', 'stageId' => 's'], 'closedDeals' => ['k1', 'k2']]]);
        $summary->expects(self::never())->method('request');

        self::assertTrue($summary->analyse($this->snapshot(['k1']), 'pt-BR')['cached']);
    }

    public function testTheStoreKeepsAConcurrentAnalysisThatKnewTheClosedDeals(): void
    {
        $statement = $this->createMock(\PDOStatement::class);
        $statement->method('execute')->willReturn(true);
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('prepare')->willReturn($statement);
        $em = $this->createMock(EntityManager::class);
        $em->method('getPDO')->willReturn($pdo);
        $build = function (array $known) use ($em): Verdicts {
            $verdicts = $this->getMockBuilder(Verdicts::class)->setConstructorArgs([$em])->onlyMethods(['find'])->getMock();
            $verdicts->method('find')->willReturn(['verdict' => 'new_deal',
                'card' => ['proposedDeal' => ['name' => 'Ana', 'funnelId' => 'f', 'stageId' => 's'], 'closedDeals' => $known]]);
            return $verdicts;
        };
        $em->expects(self::once())->method('createEntity');

        $build(['k1'])->store('tenant', 'ChatwootConversation', 'c1', 'rev', 'pt-BR', 'scope', 'none', 'r', null, null, null, ['k1']);
        $build([])->store('tenant', 'ChatwootConversation', 'c1', 'rev', 'pt-BR', 'scope', 'none', 'r', null, null, null, ['k1']);
    }
}

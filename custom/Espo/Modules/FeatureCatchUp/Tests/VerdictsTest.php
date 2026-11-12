<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCatchUp\Tests;

use Espo\Modules\FeatureCatchUp\Services\Verdicts;
use Espo\ORM\EntityManager;
use PHPUnit\Framework\TestCase;

class VerdictsTest extends TestCase
{
    /** @param list<array{string, string, int}> $rows subject id, verdict, resolved */
    private function verdicts(array $rows, ?string &$sql = null): Verdicts
    {
        $statement = $this->createMock(\PDOStatement::class);
        $statement->method('execute')->willReturn(true);
        $statement->method('fetchAll')->willReturn($rows);
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('prepare')->willReturnCallback(function (string $query) use ($statement, &$sql) {
            $sql = $query;
            return $statement;
        });
        $em = $this->createMock(EntityManager::class);
        $em->method('getPDO')->willReturn($pdo);
        return new Verdicts($em);
    }

    public function testACardSomeoneActedOnStaysOutOfTheDeckUntilTheRevisionMoves(): void
    {
        $judged = $this->verdicts([
            ['waiting', 'customer_waiting', 0],
            ['resolved', 'follow_up_due', 1],
            // Another reader's analysis at the same revision, stored after the card was acted on.
            ['resolvedThenJudged', 'customer_waiting', 1],
            ['resolvedThenJudged', 'promise_due', 0],
            ['quietThenCard', 'none', 1],
            ['quietThenCard', 'customer_waiting', 0],
            ['quiet', 'scheduled', 0],
        ], $sql)->judged('ChatwootConversation', [
            'waiting' => 'r1', 'resolved' => 'r2', 'resolvedThenJudged' => 'r3', 'quietThenCard' => 'r4', 'quiet' => 'r5', 'unjudged' => 'r6',
        ]);

        self::assertStringContainsString('resolved_at IS NOT NULL', $sql);
        self::assertSame([
            'waiting' => 'customer_waiting', 'resolved' => 'none', 'resolvedThenJudged' => 'none',
            'quietThenCard' => 'customer_waiting', 'quiet' => 'scheduled',
        ], $judged);
    }

    public function testAProposalIsTheNewestUnresolvedCardThatOffersADealReplyCardsIncluded(): void
    {
        $deal = ['name' => 'Alysson - Demonstração', 'amount' => null, 'funnelId' => 'f', 'stageId' => 's'];
        $proposals = $this->verdicts([
            // A reply card that also offers the deal (prompt 15).
            ['conversation_id' => 'reply', 'card' => json_encode(['headline' => 'Enviar o link', 'proposedDeal' => $deal]), 'reason' => 'r'],
            ['conversation_id' => 'found', 'card' => json_encode(['proposedDeal' => $deal]), 'reason' => 'r'],
            // A newer card without a deal hides an older proposal at the same revision.
            ['conversation_id' => 'overtaken', 'card' => json_encode(['headline' => 'Responder']), 'reason' => 'r'],
            ['conversation_id' => 'overtaken', 'card' => json_encode(['proposedDeal' => $deal]), 'reason' => 'r'],
            ['conversation_id' => 'plain', 'card' => json_encode(['headline' => 'Responder', 'proposedDeal' => null]), 'reason' => 'r'],
        ], $sql)->proposals(['reply' => 'r1', 'found' => 'r2', 'overtaken' => 'r3', 'plain' => 'r4']);

        self::assertStringNotContainsString("verdict = 'new_deal'", $sql);
        self::assertStringContainsString('resolved_at IS NULL', $sql);
        self::assertSame(['reply', 'found'], array_keys($proposals));
        self::assertSame($deal, $proposals['reply']['card']['proposedDeal']);
    }

    /** A PDO whose SELECTs answer $rows (by the first matching fragment) and whose other statements are recorded. */
    private function recording(array $answers, array &$writes, array &$queries = []): Verdicts
    {
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('prepare')->willReturnCallback(function (string $sql) use ($answers, &$writes, &$queries) {
            $queries[] = $sql;
            $statement = $this->createMock(\PDOStatement::class);
            $rows = [];
            foreach ($answers as $fragment => $answer) if (str_contains($sql, $fragment)) { $rows = $answer; break; }
            $statement->method('execute')->willReturnCallback(function (array $params = []) use ($sql, &$writes) {
                if (!str_starts_with($sql, 'SELECT')) $writes[] = [$sql, $params];
                return true;
            });
            $statement->method('fetchAll')->willReturn($rows);
            return $statement;
        });
        $em = $this->createMock(EntityManager::class);
        $em->method('getPDO')->willReturn($pdo);
        return new Verdicts($em);
    }

    public function testTheClockFlipsDueVerdictsInCodeOncePerRequestAndMarksCopyWrittenForAnotherLoop(): void
    {
        $past = gmdate(DATE_ATOM, time() - 60);
        $future = gmdate(DATE_ATOM, time() + 86400);
        $contract = ['id' => 'contrato', 'side' => 'ours', 'text' => 'enviar o contrato', 'dueAt' => $past];
        $writes = [];
        $queries = [];
        $verdicts = $this->recording(['flips_at <= UTC_TIMESTAMP()' => [
            // Scheduled on the contract; the contract came due: the same loop, the same copy.
            ['id' => 'v1', 'card' => json_encode(['draft' => 'Segue o contrato', 'promise' => $contract, 'draftFor' => $contract]),
                'basis' => json_encode(['loops' => [$contract + ['status' => 'open'], ['id' => 'rg', 'side' => 'theirs', 'text' => 'mandar o RG', 'dueAt' => $future, 'status' => 'open']],
                    'quietSince' => null, 'followup' => false])],
            // Written for a loop someone resolved; the next one came due: its copy is written again.
            ['id' => 'v2', 'card' => json_encode(['draft' => 'Sobre o painel', 'promise' => $contract, 'draftFor' => ['id' => 'painel', 'side' => 'theirs', 'text' => 'ver o painel']]),
                'basis' => json_encode(['loops' => [$contract + ['status' => 'open']], 'quietSince' => null, 'followup' => false])],
        ]], $writes, $queries);

        $verdicts->flipDue('tenant');
        $verdicts->flipDue('tenant');

        self::assertCount(1, array_filter($queries, fn ($sql) => str_contains($sql, 'flips_at <= UTC_TIMESTAMP()')), 'once per request');
        self::assertStringContainsString('tenant_id = :tenant', $queries[0]);
        self::assertStringContainsString('resolved_at IS NULL', $queries[0], 'a card someone acted on stays put');
        $updates = array_column($writes, 1);
        self::assertSame(['promise_due', 'promise_due'], array_column($updates, 'verdict'));
        $first = json_decode($updates[0]['card'], true);
        $second = json_decode($updates[1]['card'], true);
        self::assertArrayNotHasKey('redraft', $first);
        self::assertSame('Segue o contrato', $first['draft']);
        self::assertSame(gmdate('Y-m-d H:i:s', strtotime($future)), $updates[0]['flips'], 'the RG is next');
        self::assertTrue($second['redraft']);
        self::assertSame('enviar o contrato', $second['headline']);
        self::assertNull($updates[1]['flips']);
    }

    public function testTheCountsTakeEachSubjectsNewestVerdictSoALaterQuietOneHidesAnOlderCard(): void
    {
        $writes = [];
        $queries = [];
        $verdicts = $this->recording(['FROM catch_up_verdict v' => [
            ['noted', 'none', '7', 0], ['waiting', 'customer_waiting', '8', 0], ['noted', 'customer_waiting', '7', 0],
        ]], $writes, $queries);
        $found = $verdicts->cardConversations('tenant', 'account', '', [], 'user');
        self::assertSame(['noted' => 'none', 'waiting' => 'customer_waiting'], array_map(fn ($row) => $row['verdict'], $found));
        $sql = implode("\n", $queries);
        self::assertStringContainsString('ORDER BY v.created_at DESC', $sql);
        self::assertStringNotContainsString('INTERVAL 24 HOUR', $sql, 'a card counts until something moves it, not for a day');
        self::assertStringContainsString('c.last_message_received_at', $sql, 'a message overtakes a verdict, activity does not');
    }

    public function testAnAnalysisReplacesACardMarkedForANewDraft(): void
    {
        $writes = [];
        $pdo = $this->createMock(\PDO::class);
        $pdo->method('prepare')->willReturnCallback(function (string $sql) use (&$writes) {
            $statement = $this->createMock(\PDOStatement::class);
            $statement->method('execute')->willReturnCallback(function () use ($sql, &$writes) { if (!str_starts_with($sql, 'SELECT')) $writes[] = $sql; return true; });
            $statement->method('fetch')->willReturn(['verdict' => 'promise_due', 'reason' => 'r', 'card' => json_encode(['redraft' => true])]);
            return $statement;
        });
        $em = $this->createMock(EntityManager::class);
        $em->method('getPDO')->willReturn($pdo);
        $created = null;
        $em->method('createEntity')->willReturnCallback(function (string $type, array $data) use (&$created) { $created = $data; return $this->createMock(\Espo\ORM\Entity::class); });

        (new Verdicts($em))->store('tenant', 'Opportunity', 'deal', 'rev', 'pt-BR', 'scope', 'promise_due', 'r', ['draft' => 'novo'],
            ['loops' => []], null);

        self::assertStringContainsString('revision = :revision', $writes[1], 'the redraft row at this revision goes');
        self::assertSame('novo', $created['card']->draft);
    }
}

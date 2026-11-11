<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCatchUp\Tests;

use DateTimeImmutable;
use DateTimeZone;
use Espo\Entities\User;
use Espo\ORM\Entity;
use Espo\ORM\EntityManager;
use Espo\Modules\FeatureCatchUp\Services\DealTargets;
use Espo\Modules\FeatureCatchUp\Services\Loops;
use Espo\Modules\FeatureCatchUp\Services\Parties;
use Espo\Modules\FeatureCatchUp\Services\Records;
use Espo\Modules\FeatureCatchUp\Services\Summary;
use Espo\Modules\FeatureCatchUp\Services\Verdicts;
use PHPUnit\Framework\TestCase;

class RecordsTest extends TestCase
{
    private static function entries(): array
    {
        return array_map(fn ($minute) => ['id' => "m$minute", 'at' => sprintf('2026-11-05T13:%02d:00-03:00', $minute), 'sentBy' => 'customer'],
            [1, 2, 3, 4, 5, 6, 7, 8]);
    }

    public function testTheAnalysisReadsWhatCameAfterTheRecordPlusAFewBefore(): void
    {
        self::assertCount(8, Records::trim(self::entries(), null), 'no record yet: everything');
        $trimmed = Records::trim(self::entries(), ['coveredAt' => '2026-11-05T16:06:00+00:00']);
        self::assertSame(['m4', 'm5', 'm6', 'm7', 'm8'], array_column($trimmed, 'id'), 'times compare across offsets');
        self::assertSame('2026-11-05T13:08:00-03:00', Records::edge(self::entries()));
    }

    public function testOnlyOpenPromisesWithATimeAreDue(): void
    {
        $record = ['promises' => [
            ['status' => 'kept', 'dueAt' => '2026-11-05T10:00:00-03:00'],
            ['status' => 'open', 'dueAt' => null],
            ['status' => 'open', 'dueAt' => '2026-11-05T18:00:00-03:00'],
            ['dueAt' => '2026-11-06T12:00:00-03:00'],
            ['status' => 'open', 'dueAt' => 'amanhã'],
        ]];
        self::assertSame((new DateTimeImmutable('2026-11-05T18:00:00-03:00'))->getTimestamp(), Records::due($record));
        self::assertNull(Records::due(['promises' => []]));
    }

    public function testTheScopeIsTheSetOfConversationsNotTheirOrder(): void
    {
        self::assertSame(Records::scope(['b', 'a']), Records::scope(['a', 'b', 'a']));
        self::assertNotSame(Records::scope(['a']), Records::scope(['a', 'b']), 'a reader who sees fewer chats gets their own record');
    }

    public function testAPromisePassingItsDueIsTheClockNotANewRevision(): void
    {
        $now = new DateTimeImmutable('2026-11-05 21:00:00', new DateTimeZone('UTC'));
        $due = $now->getTimestamp() + 3600;
        $basis = Loops::basis([['id' => 'c', 'side' => 'ours', 'text' => 'enviar o contrato', 'dueAt' => gmdate(DATE_ATOM, $due), 'status' => 'open']], [], null);
        self::assertSame($due, Loops::flipsAt($basis, $now), 'the verdict is picked again when the promise comes due');
        self::assertSame('scheduled', Loops::pick($basis['loops'], Loops::factsAt($basis, $now), $now)['verdict']);
        $later = $now->modify('+2 hours');
        self::assertSame('promise_due', Loops::pick($basis['loops'], Loops::factsAt($basis, $later), $later)['verdict']);
        self::assertNull(Loops::flipsAt($basis, $later), 'nothing else is coming');
    }

    public function testTheRecordIsStoredAndTheVerdictFiledUnderTheRevisionTheReaderRead(): void
    {
        $record = ['agreed' => [['key' => 'valor', 'value' => 'R$ 800', 'messageId' => '12']],
            'promises' => [['id' => 'contrato', 'side' => 'ours', 'text' => 'enviar o contrato', 'dueAt' => '2026-11-01T18:00:00-03:00', 'status' => 'open']]];
        $records = $this->createMock(Records::class);
        $records->expects(self::once())->method('store')->with('tenant', 'Opportunity', 'deal', 'scope-1', $record, '2026-11-05T13:08:00-03:00');
        $verdicts = $this->createMock(Verdicts::class);
        $verdicts->method('find')->willReturn(null);
        $stored = null;
        $verdicts->method('store')->willReturnCallback(function (...$args) use (&$stored): void { $stored = $args; });
        $summary = $this->getMockBuilder(Summary::class)
            ->setConstructorArgs([$this->createMock(User::class), $verdicts, $this->createMock(Parties::class), $records, $this->createMock(DealTargets::class)])
            ->onlyMethods(['request'])->getMock();
        $summary->method('request')->willReturn(['status' => 'completed', 'card' => [
            'verdict' => 'loops', 'reason' => 'Prometemos o contrato', 'headline' => 'H',
            'promise' => ['side' => 'theirs', 'text' => 'the model no longer picks it', 'dueAt' => null], 'record' => $record]]);
        $result = $summary->analyse(['id' => 'deal', 'type' => 'opportunity', 'tenantId' => 'tenant', 'revision' => 'r-now',
            'evidenceScope' => 'scope', 'evidence' => self::entries(),
            'facts' => [], 'catchUpPolicy' => [], 'sourceScope' => 'scope-1', 'record' => null], 'pt-BR');
        self::assertSame('promise_due', $result['verdict']);
        self::assertSame('r-now', $stored[3], 'a promise past due is the clock, not another revision');
        self::assertArrayNotHasKey('record', $stored[8], 'the record is stored on its own, never on the card');
        self::assertSame('enviar o contrato', $stored[8]['promise']['text']);
    }

    public function testJaResolviKeepsTheCardsLoopByIdOrForOlderCardsBySideAndText(): void
    {
        $later = '2026-11-20T18:00:00-03:00';
        $state = ['agreed' => [], 'promises' => [
            ['id' => 'p1', 'side' => 'theirs', 'text' => 'Verificar o painel', 'dueAt' => '2026-11-05T18:00:00-03:00', 'status' => 'open', 'openedBy' => '1249107', 'closedBy' => null],
            ['id' => 'p2', 'side' => 'ours', 'text' => 'Verificar o painel', 'dueAt' => $later, 'status' => 'open', 'openedBy' => '1249108', 'closedBy' => null],
            ['id' => 'p3', 'side' => 'theirs', 'text' => 'Mandar o RG', 'dueAt' => null, 'status' => 'kept', 'openedBy' => '1', 'closedBy' => '2'],
        ]];
        $keep = function (array $loop) use ($state): array {
            $select = $this->createMock(\PDOStatement::class);
            $select->method('execute')->willReturn(true);
            $select->method('fetchAll')->willReturn([['id' => 'rec', 'state' => json_encode($state)]]);
            $updates = [];
            $update = $this->createMock(\PDOStatement::class);
            $update->method('execute')->willReturnCallback(function (array $values) use (&$updates) {
                $updates[] = $values;
                return true;
            });
            $pdo = $this->createMock(\PDO::class);
            $pdo->method('prepare')->willReturnCallback(fn (string $sql) => str_starts_with($sql, 'SELECT') ? $select : $update);
            $em = $this->createMock(EntityManager::class);
            $em->method('getPDO')->willReturn($pdo);
            $closed = (new Records($em))->keep('ChatwootConversation', 'conv', $loop, 'user:erivania');
            return [$closed, $updates];
        };

        // A card stored before loops carried an id: side and text pick it, so the customer's loop closes and ours stays.
        [$closed, $updates] = $keep(['side' => 'theirs', 'text' => 'Verificar o painel', 'dueAt' => '2026-11-05T18:00:00-03:00']);
        self::assertTrue($closed);
        self::assertCount(1, $updates);
        $promises = json_decode($updates[0]['state'], true)['promises'];
        self::assertSame(['kept', 'user:erivania'], [$promises[0]['status'], $promises[0]['closedBy']]);
        self::assertSame(['open', 'kept'], [$promises[1]['status'], $promises[2]['status']]);
        self::assertSame('2', $promises[2]['closedBy'], 'a loop already closed is left as it was');
        self::assertSame((new DateTimeImmutable($later))->getTimestamp(), $updates[0]['due'], 'the kept loop\'s time stops counting');

        [$closed, $updates] = $keep(['id' => 'p2', 'side' => 'ours', 'text' => 'texto reescrito', 'dueAt' => $later]);
        self::assertTrue($closed);
        self::assertSame(['open', 'kept'], array_column(array_slice(json_decode($updates[0]['state'], true)['promises'], 0, 2), 'status'));
        self::assertSame((new DateTimeImmutable('2026-11-05T18:00:00-03:00'))->getTimestamp(), $updates[0]['due'], 'the loop still open still counts');

        [$closed, $updates] = $keep(['id' => 'p3', 'side' => 'theirs', 'text' => 'Mandar o RG', 'dueAt' => null]);
        self::assertFalse($closed, 'a loop the analysis already closed is not closed again');
        self::assertSame([], $updates);
    }
}

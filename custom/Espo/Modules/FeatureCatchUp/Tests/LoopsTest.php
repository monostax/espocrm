<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCatchUp\Tests;

use DateTimeImmutable;
use DateTimeZone;
use Espo\Modules\FeatureCatchUp\Services\Feed;
use Espo\Modules\FeatureCatchUp\Services\Loops;
use PHPUnit\Framework\TestCase;

class LoopsTest extends TestCase
{
    private const NOW = '2026-11-10T15:00:00-03:00';

    private static function loop(string $id, string $side, ?string $dueAt = null, string $status = 'open'): array
    {
        return ['id' => $id, 'side' => $side, 'text' => "fazer $id", 'dueAt' => $dueAt, 'status' => $status, 'openedBy' => '1'];
    }

    private static function verdict(string $answer, array $loops, array $facts = [], bool $stepPending = false): array
    {
        return Loops::verdict($answer, ['agreed' => [], 'promises' => $loops], $facts, new DateTimeImmutable(self::NOW), $stepPending);
    }

    public function testOursWithoutATimeIsACustomerWaitingNowAndComesFirst(): void
    {
        $result = self::verdict('loops', [
            self::loop('late', 'theirs', '2026-11-08T12:00:00-03:00'),
            self::loop('promised', 'ours', '2026-11-09T18:00:00-03:00'),
            self::loop('asked', 'ours'),
        ]);
        self::assertSame('customer_waiting', $result['verdict']);
        self::assertSame(['id' => 'asked', 'side' => 'ours', 'text' => 'fazer asked', 'dueAt' => null], $result['loop']);
    }

    public function testOurPromisePastItsTimeIsDueAndTheEarliestOneShows(): void
    {
        $result = self::verdict('loops', [
            self::loop('later', 'ours', '2026-11-10T14:00:00-03:00'),
            self::loop('first', 'ours', '2026-11-09T18:00:00-03:00'),
            self::loop('future', 'ours', '2026-11-12T12:00:00-03:00'),
        ]);
        self::assertSame(['promise_due', 'fazer first'], [$result['verdict'], $result['loop']['text']]);
    }

    public function testTheirsIsChasedOnceItsTimePassesOrTheChatIsQuietPastTheThreshold(): void
    {
        $promised = self::loop('comprovante', 'theirs', '2026-11-10T12:00:00-03:00');
        self::assertSame('follow_up_due', self::verdict('loops', [$promised], ['quietDays' => 0])['verdict'], 'their own time passed');
        $implied = self::loop('aprovar', 'theirs');
        self::assertSame('scheduled', self::verdict('loops', [$implied], ['quietDays' => Feed::FOLLOW_UP_AFTER_DAYS - 1])['verdict']);
        self::assertSame('follow_up_due', self::verdict('loops', [$implied], ['quietDays' => Feed::FOLLOW_UP_AFTER_DAYS])['verdict']);
        $future = self::loop('socio', 'theirs', '2026-11-13T12:00:00-03:00');
        self::assertSame('scheduled', self::verdict('loops', [$future], ['quietDays' => 9])['verdict'],
            'a customer who said when they would answer is not chased before then');
    }

    public function testTheAiFollowUpNudgesInsteadOfAPerson(): void
    {
        $late = self::loop('aprovar', 'theirs', '2026-11-09T12:00:00-03:00');
        self::assertSame('scheduled', self::verdict('loops', [$late], ['aiFollowupActive' => true, 'quietDays' => 5])['verdict']);
        self::assertSame('customer_waiting', self::verdict('loops', [$late, self::loop('asked', 'ours')], ['aiFollowupActive' => true])['verdict'],
            'a customer waiting on us is still ours to answer');
    }

    public function testNothingDueYetIsAgendaWithTheNextLoopToComeDue(): void
    {
        $result = self::verdict('loops', [
            self::loop('friday', 'theirs', '2026-11-14T12:00:00-03:00'),
            self::loop('tomorrow', 'ours', '2026-11-11T12:00:00-03:00'),
        ]);
        self::assertSame(['scheduled', 'fazer tomorrow'], [$result['verdict'], $result['loop']['text']]);
        self::assertContains('scheduled', Loops::QUIET);
        self::assertArrayNotHasKey('scheduled', Loops::BUCKETS);
    }

    public function testClosedLoopsAndAnswersOtherThanLoopsMakeNoLoopCard(): void
    {
        $closed = [self::loop('done', 'ours', null, 'kept'), self::loop('gone', 'theirs', null, 'dropped')];
        self::assertSame(['verdict' => 'none', 'loop' => null], self::verdict('loops', $closed));
        self::assertSame('none', self::verdict('none', [self::loop('asked', 'ours')])['verdict'], 'the model may still say nobody is a customer here');
        self::assertSame('none', self::verdict('customer_waiting', [self::loop('asked', 'ours')])['verdict'], 'old verdicts are not answers');
        self::assertSame('decided', self::verdict('decided', [self::loop('asked', 'ours')])['verdict']);
        self::assertSame('new_deal', self::verdict('new_deal', [])['verdict']);
        self::assertSame('none', Loops::verdict('loops', null, [], new DateTimeImmutable(self::NOW))['verdict']);
    }

    public function testADealIsStalledOnlyWhenNothingIsOpenAndNothingScheduled(): void
    {
        self::assertSame('next_step_missing', self::verdict('next_step_missing', [])['verdict']);
        self::assertSame('none', self::verdict('next_step_missing', [], [], true)['verdict'], 'a pending next step means it is not stalled');
        self::assertSame('customer_waiting', self::verdict('next_step_missing', [self::loop('asked', 'ours')])['verdict'],
            'with a loop open the loops decide');
    }

    public function testBucketsSplitOurMovesFromTheCustomers(): void
    {
        self::assertSame('customer', Loops::BUCKETS['follow_up_due']);
        foreach (['customer_waiting', 'promise_due', 'decided', 'next_step_missing', 'new_deal'] as $verdict) {
            self::assertSame('us', Loops::BUCKETS[$verdict], $verdict);
        }
    }

    public function testTheSortKeyFollowsTheDeckOrder(): void
    {
        $now = new DateTimeImmutable('2026-11-10 18:00:00', new DateTimeZone('UTC'));
        $key = fn (?string $customer, ?string $business, ?string $last) => Feed::sortKey($customer, $business, $last, $now);
        $waitedLong = $key('2026-11-05 10:00:00', '2026-11-04 10:00:00', '2026-11-05 10:00:00');
        $waitedShort = $key('2026-11-10 10:00:00', null, '2026-11-10 10:00:00');
        $waitedWeeks = $key('2026-10-25 10:00:00', '2026-10-24 10:00:00', '2026-10-25 10:00:00');
        $quiet = $key('2026-11-01 10:00:00', '2026-11-02 10:00:00', '2026-11-02 10:00:00');
        $moving = $key('2026-11-10 09:00:00', '2026-11-10 10:00:00', '2026-11-10 10:00:00');
        $dormant = $key('2026-10-01 10:00:00', '2026-10-02 10:00:00', '2026-10-02 10:00:00');
        $keys = [$waitedLong, $waitedShort, $waitedWeeks, $quiet, $moving, $dormant, $key(null, null, null)];
        $sorted = $keys;
        usort($sorted, fn ($a, $b) => $a <=> $b);
        self::assertSame($keys, $sorted, 'longest recent wait, shorter wait, older wait, quiet, moving, dormant, unknown');
    }

    public function testTheClockFlipsAVerdictWhenALoopComesDueOrTheCustomerHasBeenQuietLongEnough(): void
    {
        $now = new DateTimeImmutable(self::NOW);
        $quietSince = '2026-11-10 12:00:00';
        $nudge = (new DateTimeImmutable($quietSince, new DateTimeZone('UTC')))->getTimestamp() + Feed::FOLLOW_UP_AFTER_DAYS * 86400;
        $basis = Loops::basis([self::loop('rg', 'theirs'), self::loop('contrato', 'ours', '2026-11-14T18:00:00-03:00')], [], $quietSince);
        self::assertSame($nudge, Loops::flipsAt($basis, $now), 'a quiet customer is nudged once the follow-up threshold passes');
        self::assertSame('scheduled', Loops::pick($basis['loops'], Loops::factsAt($basis, $now), $now)['verdict']);
        $then = (new DateTimeImmutable('@' . $nudge));
        $picked = Loops::pick($basis['loops'], Loops::factsAt($basis, $then), $then);
        self::assertSame(['follow_up_due', 'rg'], [$picked['verdict'], $picked['loop']['id']]);
        self::assertSame((new DateTimeImmutable('2026-11-14T18:00:00-03:00'))->getTimestamp(), Loops::flipsAt($basis, $then),
            'our promise coming due is next');

        $armed = Loops::basis([self::loop('rg', 'theirs', '2026-11-11T12:00:00-03:00')], ['aiFollowupActive' => true], $quietSince);
        self::assertNull(Loops::flipsAt($armed, $now), 'with the AI follow-up armed, their late loops are the AI\'s to chase');
        self::assertNull(Loops::basis([], [], $quietSince), 'no loops, nothing for the clock');
        $waiting = Loops::basis([self::loop('preco', 'ours')], [], $quietSince);
        self::assertNull(Loops::flipsAt($waiting, $now), 'a customer waiting on us stays a card until a message moves it');
    }

    public function testTheSameLoopIsMatchedByIdOrBySideAndText(): void
    {
        self::assertTrue(Loops::same(['id' => 'a', 'side' => 'ours', 'text' => 'x'], ['id' => 'a', 'side' => 'ours', 'text' => 'y']));
        self::assertFalse(Loops::same(['id' => 'a', 'side' => 'ours', 'text' => 'x'], ['id' => 'b', 'side' => 'ours', 'text' => 'x']));
        self::assertTrue(Loops::same(['side' => 'theirs', 'text' => 'mandar o RG '], ['side' => 'theirs', 'text' => 'mandar o RG']));
        self::assertFalse(Loops::same(['side' => 'theirs', 'text' => 'x'], null));
    }
}

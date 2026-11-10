<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCatchUp\Tests;

use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Utils\Log;
use Espo\Modules\FeatureCatchUp\Classes\DeckRequest;
use Espo\Modules\FeatureCatchUp\Services\Feed;
use Espo\Modules\FeatureCatchUp\Services\Prefetch;
use Espo\Modules\FeatureCatchUp\Services\ServiceFeed;
use Espo\Modules\FeatureCatchUp\Services\Summary;
use PHPUnit\Framework\TestCase;

class PrefetchTest extends TestCase
{
    /** @var list<array{string, ?string}> subject and request id of each Summary call */
    private array $calls = [];

    /**
     * @param array<string, list<array{id: string, verdict: ?string}>> $pages lane => items (one page)
     * @param array<string, list<array<string, mixed>>> $answers subject => Summary answers, in call order
     */
    private function prefetch(array $pages, array $answers, array $failing = []): Prefetch
    {
        $feed = $this->createMock(Feed::class);
        $feed->method('list')->willReturn(['items' => $pages['deal'] ?? [], 'cursor' => null]);
        $feed->method('snapshot')->willReturnCallback(fn (string $id) => ['id' => $id, 'type' => 'opportunity']);
        $service = $this->createMock(ServiceFeed::class);
        $service->method('list')->willReturn(['items' => $pages['service'] ?? [], 'cursor' => null]);
        $service->method('snapshot')->willReturnCallback(function (string $id) use ($failing) {
            if (in_array($id, $failing, true)) throw new Forbidden();
            return ['id' => $id, 'type' => 'conversation'];
        });
        $summary = $this->createMock(Summary::class);
        $summary->method('analyse')->willReturnCallback(function (array $snapshot, string $locale, ?string $requestId) use (&$answers) {
            $this->calls[] = [$snapshot['id'], $requestId];
            $next = !empty($answers[$snapshot['id']]) ? array_shift($answers[$snapshot['id']]) : null;
            return $next ?? ['status' => 'pending', 'requestId' => 'r-' . $snapshot['id']];
        });
        return new Prefetch($feed, $service, $summary, $this->createMock(Log::class));
    }

    private static function item(string $id, ?string $verdict = null): array
    {
        return ['id' => $id, 'verdict' => $verdict];
    }

    public function testDealsComeFirstThenConversationsUpToTheLimitSkippingWhatIsJudged(): void
    {
        $done = ['status' => 'completed', 'verdict' => 'customer_waiting'];
        $prefetch = $this->prefetch(
            ['deal' => [self::item('d1'), self::item('d2', 'follow_up_due')], 'service' => [self::item('c1'), self::item('c2'), self::item('c3')]],
            ['d1' => [$done], 'c1' => [['status' => 'completed', 'verdict' => 'none']], 'c2' => [$done]],
        );

        $result = $prefetch->run(12, 'America/Sao_Paulo', 'pt-BR', 3, 0);

        self::assertSame([['d1', null], ['c1', null], ['c2', null]], $this->calls, 'd2 is already judged; c3 is past the limit.');
        self::assertSame(['picked' => 3, 'cards' => 2, 'quiet' => 1, 'pending' => 0, 'failed' => 0, 'waiting' => []], $result);
    }

    public function testPollsQueuedAnalysesWithTheirRequestUntilTheyComplete(): void
    {
        $prefetch = $this->prefetch(['service' => [self::item('c1')]], ['c1' => [
            ['status' => 'pending', 'requestId' => 'req-1'],
            ['status' => 'pending', 'requestId' => 'req-1'],
            ['status' => 'completed', 'verdict' => 'new_deal'],
        ]]);

        $result = $prefetch->run(12, 'UTC', 'pt-BR', 15, 10, 0);

        self::assertSame([['c1', null], ['c1', 'req-1'], ['c1', 'req-1']], $this->calls);
        self::assertSame(1, $result['cards']);
        self::assertSame(0, $result['pending']);
    }

    public function testASupersededOrExpiredRequestIsAskedAgainWithoutItsId(): void
    {
        $prefetch = $this->prefetch(['service' => [self::item('c1')]], ['c1' => [
            ['status' => 'pending', 'requestId' => 'old'],
            ['status' => 'superseded', 'requestId' => 'old'],
            ['status' => 'completed', 'verdict' => 'none'],
        ]]);

        $result = $prefetch->run(12, 'UTC', 'pt-BR', 15, 10, 0);

        self::assertSame([['c1', null], ['c1', 'old'], ['c1', null]], $this->calls);
        self::assertSame(1, $result['quiet']);
    }

    public function testFailuresLimitsAndRefusalsAreCountedAndTheRestGoesOn(): void
    {
        $prefetch = $this->prefetch(
            ['service' => [self::item('c1'), self::item('c2'), self::item('c3')]],
            ['c1' => [['status' => 'limited']], 'c3' => [['status' => 'completed', 'verdict' => 'customer_waiting']]],
            ['c2'],
        );

        $result = $prefetch->run(12, 'UTC', 'pt-BR', 15, 0);

        self::assertSame(['picked' => 3, 'cards' => 1, 'quiet' => 0, 'pending' => 0, 'failed' => 2, 'waiting' => []], $result);
    }

    public function testStopsWaitingAtTheDeadlineAndReportsWhatIsStillPending(): void
    {
        $prefetch = $this->prefetch(['deal' => [self::item('d1')]], []);

        $result = $prefetch->run(12, 'UTC', 'pt-BR', 15, 0);

        self::assertSame(1, $result['pending']);
        self::assertSame([['deal', 'd1']], $result['waiting'], 'For a later poll.');
        self::assertSame([['d1', null]], $this->calls);
    }

    public function testAPollAsksOnceAboutEachQueuedAnalysisAndReturnsWhatIsStillPending(): void
    {
        $prefetch = $this->prefetch(['service' => [self::item('c9')]], [
            'c1' => [['status' => 'completed', 'verdict' => 'new_deal']],
            'd1' => [['status' => 'pending', 'requestId' => 'r-d1']],
        ]);

        $result = $prefetch->poll(12, 'UTC', 'pt-BR', [['service', 'c1'], ['deal', 'd1'], ['nope', 'x']]);

        self::assertSame([['c1', null], ['d1', null]], $this->calls, 'Only the queued subjects, never new picks.');
        self::assertSame(['picked' => 0, 'cards' => 1, 'quiet' => 0, 'pending' => 1, 'failed' => 0, 'waiting' => [['deal', 'd1']]], $result);
    }

    public function testTheDeckRequestCarriesTheDecksParameters(): void
    {
        $request = new DeckRequest(['accountId' => '12', 'timeZone' => 'America/Sao_Paulo']);
        $next = $request->withQuery('cursor', '20');

        self::assertSame('12', $next->getQueryParam('accountId'));
        self::assertSame('20', $next->getQueryParam('cursor'));
        self::assertNull($request->getQueryParam('cursor'));
        self::assertNull($next->withQuery('cursor', null)->getQueryParam('cursor'));
        self::assertSame('GET', $request->getMethod());
        self::assertSame('x', (new DeckRequest([], ['accountId' => 'x']))->getParsedBody()->accountId);
    }
}

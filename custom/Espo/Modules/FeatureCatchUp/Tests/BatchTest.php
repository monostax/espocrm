<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCatchUp\Tests;

use Espo\Core\Api\Request;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Utils\Log;
use Espo\Modules\FeatureCatchUp\Services\Batch;
use Espo\Modules\FeatureCatchUp\Services\Feed;
use Espo\Modules\FeatureCatchUp\Services\ServiceFeed;
use Espo\Modules\FeatureCatchUp\Services\Summary;
use PHPUnit\Framework\TestCase;

class BatchTest extends TestCase
{
    /** @var list<string> snapshots built, as "lane:id" */
    private array $snapshots = [];
    /** @var list<array{string, string, ?string}> subject, locale and request id of each Summary call */
    private array $analyses = [];

    /** @param array<string, string> $statuses request id => backend status */
    private function batch(array $statuses = [], array $refused = []): Batch
    {
        $feed = $this->createMock(Feed::class);
        $feed->method('snapshot')->willReturnCallback(function (string $id) use ($refused) {
            $this->snapshots[] = "deals:$id";
            if (in_array($id, $refused, true)) throw new Forbidden('No conversation of this deal can be opened.');
            return ['id' => $id, 'type' => 'opportunity'];
        });
        $service = $this->createMock(ServiceFeed::class);
        $service->method('snapshot')->willReturnCallback(function (string $id) {
            $this->snapshots[] = "service:$id";
            return ['id' => $id, 'type' => 'conversation'];
        });
        $summary = $this->createMock(Summary::class);
        $summary->method('analyse')->willReturnCallback(function (array $snapshot, string $locale, ?string $requestId) {
            $this->analyses[] = [$snapshot['id'], $locale, $requestId];
            return $requestId ? ['status' => 'completed', 'cached' => false, 'verdict' => 'customer_waiting', 'card' => ['headline' => 'x']]
                : ['status' => 'pending', 'requestId' => str_repeat('a', 64), 'cached' => false, 'verdict' => null, 'card' => null];
        });
        $summary->method('statuses')->willReturnCallback(fn (array $ids) => array_combine($ids, array_map(fn ($id) => $statuses[$id] ?? 'expired', $ids)));
        return new Batch($feed, $service, $summary, $this->createMock(Log::class));
    }

    private function request(array $items, string $locale = 'pt_BR'): Request
    {
        $request = $this->createMock(Request::class);
        $request->method('getParsedBody')->willReturn((object) [
            'accountId' => '12', 'timeZone' => 'America/Sao_Paulo', 'locale' => $locale,
            'items' => array_map(fn (array $item) => (object) $item, $items),
        ]);
        return $request;
    }

    public function testStartsEverySubjectOfEitherLaneWithItsSnapshot(): void
    {
        $result = $this->batch()->start($this->request([['lane' => 'deals', 'id' => 'd1'], ['lane' => 'service', 'id' => 'c1']]));

        self::assertSame(['deals:d1', 'service:c1'], $this->snapshots);
        self::assertSame([['d1', 'pt-BR', null], ['c1', 'pt-BR', null]], $this->analyses);
        self::assertSame(['deals', 'd1', 'pending'], [$result['items'][0]['lane'], $result['items'][0]['id'], $result['items'][0]['status']]);
        self::assertSame(['id' => 'c1', 'type' => 'conversation'], $result['items'][1]['snapshot']);
    }

    public function testASubjectTheReaderCannotOpenFailsAloneAndTheRestGoesOn(): void
    {
        $result = $this->batch([], ['d1'])->start($this->request([['lane' => 'deals', 'id' => 'd1'], ['lane' => 'deals', 'id' => 'd2']], 'en'));

        self::assertSame(['lane' => 'deals', 'id' => 'd1', 'status' => 'error', 'error' => 'No conversation of this deal can be opened.'], $result['items'][0]);
        self::assertSame('pending', $result['items'][1]['status']);
        self::assertSame([['d2', 'en', null]], $this->analyses);
    }

    public function testPollingBuildsASnapshotAgainOnlyForFinishedAnalyses(): void
    {
        [$done, $waiting, $limited, $lost] = [str_repeat('1', 64), str_repeat('2', 64), str_repeat('3', 64), str_repeat('4', 64)];
        $result = $this->batch([$done => 'completed', $waiting => 'pending', $limited => 'limited'])->status($this->request([
            ['lane' => 'deals', 'id' => 'd1', 'requestId' => $done],
            ['lane' => 'service', 'id' => 'c1', 'requestId' => $waiting],
            ['lane' => 'deals', 'id' => 'd2', 'requestId' => $limited],
            ['lane' => 'deals', 'id' => 'd3', 'requestId' => $lost],
        ]));

        self::assertSame(['deals:d1'], $this->snapshots, 'Pending, limited and unknown requests cost no snapshot.');
        self::assertSame([['d1', 'pt-BR', $done]], $this->analyses, 'The finished one is stored under its request.');
        self::assertSame(['completed', 'pending', 'limited', 'expired'], array_column($result['items'], 'status'));
        self::assertSame('customer_waiting', $result['items'][0]['verdict']);
        self::assertSame(['lane' => 'service', 'id' => 'c1', 'status' => 'pending', 'requestId' => $waiting], $result['items'][1]);
    }

    public function testRejectsMoreThanABatchAndMalformedItems(): void
    {
        $batch = $this->batch();
        foreach ([
            fn () => $batch->start($this->request(array_fill(0, Batch::MAX_START + 1, ['lane' => 'deals', 'id' => 'd1']))),
            fn () => $batch->start($this->request([])),
            fn () => $batch->start($this->request([['lane' => 'other', 'id' => 'd1']])),
            fn () => $batch->start($this->request([['lane' => 'deals', 'id' => '../x']])),
            fn () => $batch->status($this->request([['lane' => 'deals', 'id' => 'd1', 'requestId' => 'nope']])),
        ] as $call) {
            try {
                $call();
                self::fail('Expected a bad request.');
            } catch (BadRequest) {
                self::assertSame([], $this->snapshots);
            }
        }
    }

    public function testTheAnalysisLocaleFollowsTheDeckLocale(): void
    {
        self::assertSame(['pt-BR', 'pt-BR', 'pt-BR', 'en', 'en'], array_map([Summary::class, 'locale'], ['pt_BR', 'pt', 'pt-BR', 'en', null]));
    }
}

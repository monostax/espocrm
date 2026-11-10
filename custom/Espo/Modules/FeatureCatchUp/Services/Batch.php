<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCatchUp\Services;

use Espo\Core\Api\Request;
use Espo\Core\Exceptions\BadRequest;
use Espo\Core\Exceptions\Conflict;
use Espo\Core\Exceptions\Forbidden;
use Espo\Core\Exceptions\NotFound;
use Espo\Core\Exceptions\ServiceUnavailable;
use Espo\Core\Utils\Log;
use Throwable;

/**
 * The deck's analyses, several subjects per call (decided 2026-11-10: the deck analyses 10 ahead and adds the next 10 when
 * the reader reaches the 5th). start() builds each subject's snapshot and answers from its stored verdict or queues its
 * analysis, as the single summary routes do. status() asks the backend where the queued ones stand in one call and builds
 * a snapshot again only for those that finished: that rebuild checks access again, notices a conversation that moved,
 * and stores the verdict. Polling no longer rebuilds every snapshot every 1.5 s.
 *
 * Each subject answers on its own: one the reader can't open comes back as an error, never failing the others.
 */
class Batch
{
    /** Subjects started per call: the deck's batch. */
    public const MAX_START = 10;
    /** Queued analyses polled per call: a batch and the next one, with room for retries. */
    public const MAX_STATUS = 30;
    private const LANES = ['deals', 'service'];

    public function __construct(
        private Feed $feed,
        private ServiceFeed $service,
        private Summary $summary,
        private Log $log,
    ) {}

    /** @return array{items: list<array<string, mixed>>} */
    public function start(Request $request): array
    {
        $items = $this->items($request, self::MAX_START, false);
        $locale = Summary::locale($request->getParsedBody()->locale ?? null);
        return ['items' => array_map(fn (array $item) => $this->analyse($item['lane'], $item['id'], $request, $locale, null), $items)];
    }

    /** @return array{items: list<array<string, mixed>>} */
    public function status(Request $request): array
    {
        $items = $this->items($request, self::MAX_STATUS, true);
        $locale = Summary::locale($request->getParsedBody()->locale ?? null);
        $statuses = $this->summary->statuses(array_column($items, 'requestId'));
        return ['items' => array_map(function (array $item) use ($statuses, $request, $locale) {
            $status = $statuses[$item['requestId']] ?? 'expired';
            if ($status === 'completed') return $this->analyse($item['lane'], $item['id'], $request, $locale, $item['requestId']);
            return ['lane' => $item['lane'], 'id' => $item['id'], 'status' => $status, 'requestId' => $item['requestId']];
        }, $items)];
    }

    /** @return array<string, mixed> */
    private function analyse(string $lane, string $id, Request $request, string $locale, ?string $requestId): array
    {
        try {
            $snapshot = $lane === 'service' ? $this->service->snapshot($id, $request) : $this->feed->snapshot($id, $request);
            return ['lane' => $lane, 'id' => $id, ...$this->summary->analyse($snapshot, $locale, $requestId), 'snapshot' => $snapshot];
        } catch (Forbidden|NotFound|BadRequest|Conflict|ServiceUnavailable $e) {
            return ['lane' => $lane, 'id' => $id, 'status' => 'error', 'error' => $e->getMessage()];
        } catch (Throwable $e) {
            $this->log->error("CatchUp batch: {$lane} {$id} could not be analysed: " . $e->getMessage());
            return ['lane' => $lane, 'id' => $id, 'status' => 'error', 'error' => 'The analysis could not start.'];
        }
    }

    /** @return list<array{lane: string, id: string, requestId?: string}> */
    private function items(Request $request, int $max, bool $polling): array
    {
        $raw = $request->getParsedBody()->items ?? null;
        if (!is_array($raw) || !$raw || count($raw) > $max) throw new BadRequest('Between 1 and ' . $max . ' items are required.');
        $items = [];
        foreach ($raw as $item) {
            $item = (array) $item;
            $lane = $item['lane'] ?? null;
            $id = $item['id'] ?? null;
            if (!in_array($lane, self::LANES, true) || !is_string($id) || !preg_match('/^[a-zA-Z0-9_-]{1,36}$/D', $id)) {
                throw new BadRequest('Invalid item.');
            }
            $entry = ['lane' => $lane, 'id' => $id];
            if ($polling) {
                $requestId = $item['requestId'] ?? null;
                if (!is_string($requestId) || !preg_match('/^[a-f0-9]{64}$/D', $requestId)) throw new BadRequest('Invalid request ID.');
                $entry['requestId'] = $requestId;
            }
            $items[] = $entry;
        }
        return $items;
    }
}

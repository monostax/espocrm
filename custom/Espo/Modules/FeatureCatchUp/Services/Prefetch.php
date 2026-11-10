<?php

declare(strict_types=1);

namespace Espo\Modules\FeatureCatchUp\Services;

use Espo\Core\Utils\Log;
use Espo\Modules\FeatureCatchUp\Classes\DeckRequest;
use Throwable;

/**
 * Analyses the first cards of a reader's deck without the deck: the onboarding first read (Self-Service PLG, "Next
 * steps to launch" §7). It runs as the reader (the caller sets the user, e.g. ActorScope) through the same feeds,
 * snapshots and Summary as the deck, so the deck later finds each verdict stored under the same key and opens at
 * once. Billing, the daily limit and every access rule are the deck's own.
 *
 * Deals come first, then conversations, each in the deck's order; subjects already judged at their revision are
 * skipped, so running it again costs nothing. With no wait, it only queues the analyses and returns those still
 * pending; poll() later stores the ones that completed (a job never sleeps in a worker slot).
 */
class Prefetch
{
    /** Pages read per lane while looking for unjudged subjects. */
    private const MAX_PAGES = 3;

    public function __construct(
        private Feed $feed,
        private ServiceFeed $service,
        private Summary $summary,
        private Log $log,
    ) {}

    /**
     * @param string $locale The deck's analysis locale ('pt-BR' or 'en'): verdicts are stored per locale.
     * @return array{picked: int, cards: int, quiet: int, pending: int, failed: int, waiting: list<array{string, string}>}
     *     `waiting`: lane and id of each analysis still pending, for poll()
     */
    public function run(int $chatwootAccountId, string $timeZone, string $locale, int $limit, int $waitSeconds = 150, int $pollSeconds = 5): array
    {
        $request = new DeckRequest(['accountId' => (string) $chatwootAccountId, 'timeZone' => $timeZone]);
        $result = ['picked' => 0, 'cards' => 0, 'quiet' => 0, 'pending' => 0, 'failed' => 0];
        $pending = [];

        foreach ($this->pick($request, $limit) as [$lane, $id]) {
            $result['picked']++;
            $this->analyse($lane, $id, $request, $locale, null, $pending, $result);
        }

        return $this->wait($request, $locale, $pending, $result, $waitSeconds, $pollSeconds);
    }

    /**
     * Ask once more about analyses run() left pending: completed ones are stored, the rest are returned again.
     *
     * @param list<array{string, string}> $subjects lane and id, as run() returned them in `waiting`
     * @return array{picked: int, cards: int, quiet: int, pending: int, failed: int, waiting: list<array{string, string}>}
     */
    public function poll(int $chatwootAccountId, string $timeZone, string $locale, array $subjects): array
    {
        $request = new DeckRequest(['accountId' => (string) $chatwootAccountId, 'timeZone' => $timeZone]);
        $result = ['picked' => 0, 'cards' => 0, 'quiet' => 0, 'pending' => 0, 'failed' => 0];
        $pending = [];

        foreach ($subjects as [$lane, $id]) {
            if (!in_array($lane, ['deal', 'service'], true) || !is_string($id)) continue;
            // Without a request id the backend answers the analysis of the current evidence: done, or still pending.
            $this->analyse($lane, $id, $request, $locale, null, $pending, $result);
        }

        return $this->wait($request, $locale, $pending, $result, 0, 0);
    }

    /**
     * @param array<string, array{string, string, ?string}> $pending
     * @param array{picked: int, cards: int, quiet: int, pending: int, failed: int} $result
     * @return array{picked: int, cards: int, quiet: int, pending: int, failed: int, waiting: list<array{string, string}>}
     */
    private function wait(DeckRequest $request, string $locale, array $pending, array $result, int $waitSeconds, int $pollSeconds): array
    {
        $deadline = time() + $waitSeconds;
        while ($pending && time() < $deadline) {
            sleep(max(1, $pollSeconds));
            foreach ($pending as $key => [$lane, $id, $requestId]) {
                unset($pending[$key]);
                $this->analyse($lane, $id, $request, $locale, $requestId, $pending, $result);
            }
        }

        $result['pending'] = count($pending);
        $result['waiting'] = array_values(array_map(fn (array $entry) => [$entry[0], $entry[1]], $pending));
        return $result;
    }

    /** @return list<array{string, string}> lane and subject id, deals first */
    private function pick(DeckRequest $request, int $limit): array
    {
        $picked = [];
        foreach (['deal' => $this->feed, 'service' => $this->service] as $lane => $source) {
            $cursor = null;
            for ($page = 0; $page < self::MAX_PAGES; $page++) {
                $list = $source->list($request->withQuery('cursor', $cursor));
                foreach ($list['items'] as $item) {
                    if (count($picked) >= $limit) return $picked;
                    if (($item['verdict'] ?? null) === null) $picked[] = [$lane, (string) $item['id']];
                }
                $cursor = $list['cursor'] ?? null;
                if ($cursor === null) break;
            }
        }
        return $picked;
    }

    /**
     * One step of one subject: a fresh snapshot (what the deck sends each poll), then Summary, which answers from the
     * stored verdict, queues the analysis, or polls the queued one.
     *
     * @param array<string, array{string, string, ?string}> $pending
     * @param array{picked: int, cards: int, quiet: int, pending: int, failed: int} $result
     */
    private function analyse(string $lane, string $id, DeckRequest $request, string $locale, ?string $requestId, array &$pending, array &$result): void
    {
        try {
            $snapshot = $lane === 'deal' ? $this->feed->snapshot($id, $request) : $this->service->snapshot($id, $request);
            $answer = $this->summary->analyse($snapshot, $locale, $requestId);
        } catch (Throwable $e) {
            $result['failed']++;
            $this->log->warning("CatchUp prefetch: {$lane} {$id} could not be analysed: " . $e->getMessage());
            return;
        }

        if (($answer['status'] ?? null) === 'completed') {
            in_array($answer['verdict'] ?? null, Loops::QUIET, true) ? $result['quiet']++ : $result['cards']++;
            return;
        }

        $status = (string) ($answer['status'] ?? '');
        if (in_array($status, ['failed', 'limited'], true)) {
            $result['failed']++;
            $this->log->warning("CatchUp prefetch: {$lane} {$id} ended {$status}.");
            return;
        }

        // Pending: poll that request. Superseded (the evidence moved) or expired: ask again, which queues or finds
        // the analysis of the current evidence.
        $pending["{$lane}:{$id}"] = [$lane, $id, $status === 'pending' && is_string($answer['requestId'] ?? null) ? $answer['requestId'] : null];
    }
}

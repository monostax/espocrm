<?php

declare(strict_types=1);

namespace Espo\Core\WebSocket;

use Ratchet\ConnectionInterface;
use React\ChildProcess\Process;
use React\EventLoop\LoopInterface;

/** Authorized API projections on the native socket. Only hashes/control frames leave the server. */
final class QuerySubscriptions
{
    // Every live-record change in a tenant dirties its viewers' projections, so
    // under steady churn reads re-ran back to back. Coalesce to one run per
    // window; a quiet projection still refreshes on the next 0.25s drain.
    // Sidebar counts (COUNT queries, ~64% of checks, <10% changed) and static
    // inbox metadata/options tolerate longer windows than lists.
    private const COOLDOWN = 5.0;
    private const COUNTS_COOLDOWN = 30.0;
    private const STATIC_COOLDOWN = 60.0;

    private array $entries = [];
    private int $running = 0;
    private ?\React\EventLoop\TimerInterface $scheduled = null;
    private float $scheduledAt = 0.0;
    private array $stats = [];
    private array $pathStats = [];

    public function __construct(private string $php, private LoopInterface $loop)
    {
        // Reauthorize independently of best-effort change hints. A lost/expired
        // ACL hint must not preserve a revoked session or projection forever.
        // At 30s this sweep alone kept all four check slots busy; data reads
        // still pass API ACL, so only live signals can outlast a revocation.
        $loop->addPeriodicTimer(120, fn () => $this->invalidate());
        $loop->addPeriodicTimer(60, fn () => $this->report());
    }

    public function subscribe(ConnectionInterface $connection, string $userId, string $token, string $topic, array $config): void
    {
        $id = (string) $connection->resourceId;
        if (strlen($topic) > 24000 || count($this->entries[$id] ?? []) >= 128) return;
        $this->unsubscribe($connection, $topic);
        $this->entries[$id][$topic] = (object) [
            'connection' => $connection, 'userId' => $userId, 'token' => $token,
            'topic' => $topic, 'command' => $config['queryCheckCommand'],
            'invalidationTopic' => $config['invalidationTopic'],
            'fingerprint' => null, 'initialized' => false, 'denied' => false,
            'dirty' => true, 'process' => null, 'lastRun' => 0, 'tenantIds' => null, 'deps' => null,
            'path' => $path = self::pathOf($topic), 'cooldown' => self::cooldownOf($path),
        ];
        $this->schedule();
    }

    public function unsubscribe(ConnectionInterface $connection, ?string $topic = null): void
    {
        $id = (string) $connection->resourceId;
        $processes = [];
        foreach ($this->entries[$id] ?? [] as $key => $entry) {
            if ($topic !== null && $key !== $topic) continue;
            unset($this->entries[$id][$key]);
            if ($entry->process) $processes[spl_object_id($entry->process)] = $entry->process;
        }
        foreach ($processes as $process) {
            $inUse = array_filter($this->entries[$id] ?? [], static fn ($entry) => $entry->process === $process);
            if (!$inUse) $process->terminate();
        }
        if (empty($this->entries[$id])) unset($this->entries[$id]);
    }

    /**
     * A tenant hint dirties only viewers known to belong to that tenant. Viewers
     * with unknown or no tenants (first check pending, cross-tenant admins)
     * always match; the periodic sweep (no tenant) catches anything missed.
     *
     * Record hints also skip projections whose last check declared
     * dependencies none of the hint's records touch. Without records (access
     * resets) or without declared dependencies, the tenant rule alone applies.
     * Skipping never widens a read: every check re-runs the viewer's own read.
     *
     * @param ?list<array{type: string, id: string}> $records
     */
    public function invalidate(?string $topic = null, ?string $tenantId = null, ?array $records = null): bool
    {
        $matched = false;
        $this->count($tenantId === null ? 'sweeps' : 'hints');
        foreach ($this->entries as $entries) {
            foreach ($entries as $entry) {
                if ($topic !== null && $entry->invalidationTopic !== $topic) continue;
                if ($tenantId !== null && $entry->tenantIds && !in_array($tenantId, $entry->tenantIds, true)) {
                    $this->count('skipped');
                    continue;
                }
                if ($tenantId !== null && $records && $entry->deps && !self::touches($entry->deps, $records)) {
                    $this->count('skippedRecord');
                    continue;
                }
                $entry->dirty = true;
                $matched = true;
                $this->count('dirtied');
            }
        }
        if ($matched) $this->schedule();
        return $matched;
    }

    private function schedule(float $delay = 0.25): void
    {
        $at = microtime(true) + $delay;
        if ($this->scheduled) {
            if ($this->scheduledAt <= $at) return;
            $this->loop->cancelTimer($this->scheduled);
        }
        $this->scheduledAt = $at;
        $this->scheduled = $this->loop->addTimer($delay, function (): void {
            $this->scheduled = null;
            $this->drain();
        });
    }

    private function current(object $entry): bool
    {
        return ($this->entries[(string) $entry->connection->resourceId][$entry->topic] ?? null) === $entry;
    }

    private function drain(): void
    {
        $now = microtime(true);
        $waiting = [];
        $cooling = null;
        foreach ($this->entries as $entries) {
            foreach ($entries as $entry) {
                if (!$entry->dirty || $entry->process) continue;
                $ready = $entry->lastRun + $entry->cooldown;
                if ($ready <= $now) $waiting[] = $entry;
                else $cooling = min($cooling ?? $ready, $ready);
            }
        }
        if ($cooling !== null) $this->schedule($cooling - $now);
        usort($waiting, static fn ($a, $b) => $a->lastRun <=> $b->lastRun);
        foreach ($waiting as $entry) {
            if ($this->running >= 4) return;
            if (!$entry->dirty || $entry->process) continue;
            $batch = array_slice(array_values(array_filter($waiting, static fn ($candidate) =>
                $candidate->dirty && !$candidate->process && $candidate->connection === $entry->connection && $candidate->command === $entry->command
            )), 0, 32);
            foreach ($batch as $candidate) {
                $candidate->dirty = false;
            }
            $this->run($batch);
        }
    }

    private function run(array $batch): void
    {
        $entry = $batch[0];
        $this->running++;
        // Replace the shell so timeout/unsubscribe terminates PHP itself and
        // releases the pipes/concurrency slot, rather than orphaning a reader.
        $process = new Process('exec ' . escapeshellarg($this->php) . ' command.php ' . escapeshellarg($entry->command));
        foreach ($batch as $candidate) $candidate->process = $process;
        $this->count('processes');
        $this->count('checks', count($batch));
        foreach ($batch as $candidate) $this->countPath($candidate->path, 'checks');
        $output = '';
        try {
            $process->start($this->loop);
        } catch (\Throwable) {
            foreach ($batch as $candidate) $candidate->process = null;
            $this->running--;
            return;
        }
        // Bound stalled reads separately from a progressing batch. Publishing
        // each result avoids losing 31 completed reads when the last one stalls.
        $timeout = $this->loop->addTimer(15, fn () => $process->terminate());
        $deadline = $this->loop->addTimer(60, fn () => $process->terminate());
        $received = [];
        $process->stdout->on('data', function (string $data) use ($batch, $process, &$output, &$timeout, &$received): void {
            $output .= $data;
            while (($end = strpos($output, "\n")) !== false) {
                $line = substr($output, 0, $end);
                $output = substr($output, $end + 1);
                $message = json_decode($line, true);
                $index = $message['index'] ?? null;
                if (!is_int($index) || !isset($batch[$index]) || isset($received[$index]) || !is_array($message['result'] ?? null)) continue;
                $received[$index] = true;
                $this->loop->cancelTimer($timeout);
                $timeout = $this->loop->addTimer(15, fn () => $process->terminate());
                $this->receiveResult($batch[$index], $message['result']);
            }
            // Commands emit only small hash/control lines, never API bodies.
            if (strlen($output) > 16384) $process->terminate();
        });
        $process->stderr->on('data', static function (): void {});
        $process->on('exit', function ($code) use ($batch, &$timeout, $deadline): void {
            $this->loop->cancelTimer($timeout);
            $this->loop->cancelTimer($deadline);
            $this->running--;
            foreach ($batch as $candidate) $candidate->process = null;
            foreach ($batch as $entry) {
                if ($this->current($entry) && $code === 1) {
                    $entry->connection->event($entry->topic, (object) ['signal' => 'sessionExpired']);
                    $this->unsubscribe($entry->connection);
                    $entry->connection->close();
                }
                // Completed results are already delivered. Unfinished entries
                // keep their old lastRun so the next check prioritizes them.
            }
            $this->drain();
        });
        $process->stdin->end(json_encode(['token' => $entry->token, 'userId' => $entry->userId, 'stream' => true,
            'topics' => array_map(static fn ($candidate) => $candidate->topic, $batch)], JSON_THROW_ON_ERROR));
    }

    private function receiveResult(object $entry, array $result): void
    {
        if (!$this->current($entry)) return;
        $entry->lastRun = microtime(true);
        $tenantIds = $result['tenantIds'] ?? null;
        if (is_array($tenantIds) && array_is_list($tenantIds) && count($tenantIds) <= 64 &&
            !array_filter($tenantIds, static fn ($id) => !is_string($id) || !preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $id))) {
            $entry->tenantIds = $tenantIds;
        }
        // Re-declared on every successful check (membership changes); a denied,
        // malformed or missing declaration falls back to tenant-wide re-checks.
        $entry->deps = isset($result['fingerprint']) ? self::deps($result['deps'] ?? null) : null;
        if (($result['denied'] ?? false) === true) {
            if (!$entry->denied) $entry->connection->event($entry->topic, (object) ['signal' => 'revoked']);
            $entry->denied = true;
        } elseif (is_string($result['fingerprint'] ?? null) && preg_match('/^[a-f0-9]{64}$/D', $result['fingerprint'])) {
            $changed = $entry->denied || $entry->fingerprint !== $result['fingerprint'];
            if (!$entry->initialized || $changed) {
                $entry->connection->event($entry->topic, (object) [
                    'signal' => $entry->initialized ? 'invalidate' : 'ready',
                    'fingerprint' => $result['fingerprint'],
                ]);
            }
            $entry->initialized = true;
            $entry->denied = false;
            $entry->fingerprint = $result['fingerprint'];
            if ($changed) {
                $this->count('changed');
                $this->countPath($entry->path, 'changed');
            }
        }
    }

    /** @return ?array{types: array<string, true>, ids: array<string, true>} */
    private static function deps(mixed $deps): ?array
    {
        if (!is_array($deps) || !is_array($deps['types'] ?? null) || !is_array($deps['ids'] ?? null) ||
            !$deps['types'] || count($deps['ids']) > 400) return null;
        foreach ($deps['types'] as $type) {
            if (!is_string($type) || !preg_match('/^[A-Z][a-zA-Z0-9]{0,63}$/D', $type)) return null;
        }
        foreach ($deps['ids'] as $id) {
            if (!is_string($id) || !preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $id)) return null;
        }
        return ['types' => array_fill_keys($deps['types'], true), 'ids' => array_fill_keys($deps['ids'], true)];
    }

    private static function touches(array $deps, array $records): bool
    {
        foreach ($records as $record) {
            if (isset($deps['types'][$record['type']]) || isset($deps['ids'][$record['id']])) return true;
        }
        return false;
    }

    private function count(string $name, int $by = 1): void
    {
        $this->stats[$name] = ($this->stats[$name] ?? 0) + $by;
    }

    private function countPath(string $path, string $name): void
    {
        $this->pathStats[$path][$name] = ($this->pathStats[$path][$name] ?? 0) + 1;
    }

    private static function cooldownOf(string $path): float
    {
        if (preg_match('~/(?:counts|navigationCounts|notReadCount)$~D', $path)) return self::COUNTS_COOLDOWN;
        if (preg_match('~/(?:inboxMetadata|inboxOptions|metadata|options)$~D', $path)) return self::STATIC_COOLDOWN;
        return self::COOLDOWN;
    }

    /** The projection's API path with record ids folded, e.g. /Opportunity/:id/stream. */
    private static function pathOf(string $topic): string
    {
        if (!str_starts_with($topic, 'liveQuery.')) return 'other';
        $data = json_decode((string) base64_decode(strtr(substr($topic, 10), '-_', '+/'), true), true);
        if (!is_array($data) || !is_string($data['path'] ?? null)) return 'other';
        return (string) preg_replace('~/(?:[0-9a-f]{17}|\d+)(?=/|$)~', '/:id', $data['path']);
    }

    /** One stdout line per minute: the counters that size the next scaling phase. */
    private function report(): void
    {
        $subscriptions = 0;
        $scoped = 0;
        $paths = $this->pathStats;
        foreach ($this->entries as $entries) {
            foreach ($entries as $entry) {
                $subscriptions++;
                if ($entry->tenantIds) $scoped++;
                $paths[$entry->path]['subs'] = ($paths[$entry->path]['subs'] ?? 0) + 1;
            }
        }
        // Top paths by checks run: [open subscriptions, checks, checks that found a change].
        uasort($paths, static fn ($a, $b) => ($b['checks'] ?? 0) <=> ($a['checks'] ?? 0) ?: ($b['subs'] ?? 0) <=> ($a['subs'] ?? 0));
        $top = array_map(static fn ($p) => [$p['subs'] ?? 0, $p['checks'] ?? 0, $p['changed'] ?? 0], array_slice($paths, 0, 15, true));
        $line = ['liveQueryStats' => [
            'connections' => count($this->entries), 'subscriptions' => $subscriptions, 'tenantScoped' => $scoped,
            'running' => $this->running,
            ...array_merge(array_fill_keys(['hints', 'sweeps', 'dirtied', 'skipped', 'skippedRecord', 'processes', 'checks', 'changed'], 0), $this->stats),
            'paths' => (object) $top,
        ]];
        $this->stats = [];
        $this->pathStats = [];
        fwrite(STDOUT, json_encode($line, JSON_UNESCAPED_SLASHES) . "\n");
    }
}

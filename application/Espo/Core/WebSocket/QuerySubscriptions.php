<?php

declare(strict_types=1);

namespace Espo\Core\WebSocket;

use Ratchet\ConnectionInterface;
use React\ChildProcess\Process;
use React\EventLoop\LoopInterface;

/** Authorized API projections on the native socket. Only hashes/control frames leave the server. */
final class QuerySubscriptions
{
    // Every live-record change dirties every projection, so under steady churn
    // each viewer's reads re-ran back to back. Coalesce to one run per window;
    // a quiet projection still refreshes on the next 0.25s drain.
    private const COOLDOWN = 5.0;

    private array $entries = [];
    private int $running = 0;
    private ?\React\EventLoop\TimerInterface $scheduled = null;
    private float $scheduledAt = 0.0;

    public function __construct(private string $php, private LoopInterface $loop)
    {
        // Reauthorize independently of best-effort change hints. A lost/expired
        // ACL hint must not preserve a revoked session or projection forever.
        $loop->addPeriodicTimer(30, fn () => $this->invalidate());
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
            'dirty' => true, 'process' => null, 'lastRun' => 0,
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

    public function invalidate(?string $topic = null): bool
    {
        $matched = false;
        foreach ($this->entries as $entries) {
            foreach ($entries as $entry) {
                if ($topic !== null && $entry->invalidationTopic !== $topic) continue;
                $entry->dirty = true;
                $matched = true;
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
                $ready = $entry->lastRun + self::COOLDOWN;
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
        }
    }
}
